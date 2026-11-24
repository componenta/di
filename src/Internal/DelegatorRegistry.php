<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\CallableResolver;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\ExceptionInterface;
use Error;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Keeps track of delegator callables attached to container entries and applies
 * them in registration order.
 *
 * @phpstan-type PreparedDelegator array{
 *     delegator: callable|string|array{object|string,string},
 *     dependencies: list<string>
 * }
 *
 * @internal
 */
final class DelegatorRegistry
{
    /** @var array<string, list<PreparedDelegator>> */
    private array $raw = [];
    /** @var array<string, list<callable>> */
    private array $callables = [];
    /** @var array<string, int> */
    private array $revisions = [];
    /** @var array<array-key, array<string, true>> */
    private array $dependents = [];
    /** @var array<array-key, int> */
    private array $dependencyOrders = [];
    private int $dependencyOrder = 0;
    /** @var array<string, array<string, true>> */
    private array $registeredDependencies = [];
    /** @var array<string, array<string, true>> */
    private array $indexedDependencies = [];
    /** @var array<string, true> */
    private array $refinableDependencies = [];

    public bool $hasRegistrations {
        get => $this->raw !== [];
    }

    /** @param (Closure():DelegatorLookupScope)|null $selectionLookups */
    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
        private readonly ?ContainerInterface $lookup = null,
        private readonly ?Closure $selectionLookups = null,
    ) {}

    /** @return PreparedDelegator */
    public function prepare(string $id, mixed $delegator): array
    {
        $delegator = DependencyNormalizer::normalizeDelegatorSpecification($delegator, $id);
        return ['delegator' => $delegator, 'dependencies' => $this->dependencyIds($delegator)];
    }

    /** @param PreparedDelegator $prepared */
    public function register(string $id, array $prepared, MutationRetirement $retirement, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        $this->invalidateChain($id, $retirement);
        $delegator = $prepared['delegator'];
        $this->raw[$id][] = $prepared;
        $dependencies = $prepared['dependencies'];
        // An opaque string always keeps its exact ID. Only a provisional
        // method owner can disappear from the dependency index at resolution.
        if (count($dependencies) > 1 || (is_array($delegator) && $dependencies !== [])) {
            $this->refinableDependencies[$id] = true;
        }

        foreach ($dependencies as $dependencyId) {
            $this->registeredDependencies[$id][$dependencyId] = true;
            $this->indexedDependencies[$id][$dependencyId] = true;
            $this->linkDependency($dependencyId, $id);
        }
    }

    public function invalidate(string $id, MutationRetirement $retirement, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        $this->invalidateChain($id, $retirement);
    }

    private function invalidateChain(string $id, MutationRetirement $retirement): void
    {
        if (isset($this->callables[$id])) {
            $retirement->retain($this->callables[$id]);
            // A cold selection must be invalidated across callback reentry,
            // including when its next owner differs from the warmed route.
            if (isset($this->refinableDependencies[$id])
                || ($this->selectionLookups !== null && isset($this->indexedDependencies[$id]))
            ) {
                $this->indexDependencies($id, $this->registeredDependencies[$id] ?? []);
            }
        }
        unset($this->callables[$id]);
        $this->revisions[$id] = ($this->revisions[$id] ?? 0) + 1;
    }

    /** @param array<array-key, int> $candidates */
    public function firstDelegatedEntryAmong(array $candidates): ?string
    {
        $matches = [];
        foreach ($candidates as $id => $_) {
            if (isset($this->raw[$id])) {
                $matches[$id] = true;
            }
        }
        // Keep registration order in diagnostics when several entries conflict.
        $entry = array_key_first(count($matches) > 1 ? array_intersect_key($this->raw, $matches) : $matches);
        return $entry === null ? null : (string) $entry;
    }

    /** @return list<string> */
    public function deferredDependencies(): array
    {
        return array_map('strval', array_keys($this->dependents));
    }

    public function dependsOn(string $id): bool
    {
        return isset($this->dependents[$id]);
    }

    /** @var non-negative-int */
    public int $dependencyCount {
        get => count($this->dependents);
    }

    /**
     * @param array<array-key, int> $candidates
     * @return list<string>
     */
    public function dependenciesAmong(array $candidates): array
    {
        $matches = [];
        foreach ($candidates as $id => $_) {
            if (isset($this->dependents[$id])) {
                $matches[$id] = $this->dependencyOrders[$id];
            }
        }
        if (count($matches) > 1) {
            asort($matches, SORT_NUMERIC);
        }
        return array_map('strval', array_keys($matches));
    }

    /**
     * Invalidates every delegator chain transitively depending on any supplied
     * dependency id. The traversal is cycle-safe because an entry may itself be
     * used as a deferred callable dependency by another entry (or by a cycle).
     *
     * @param iterable<string> $dependencyIds
     * @return list<string>
     */
    public function invalidateDependencies(iterable $dependencyIds, MutationRetirement $retirement, ?object $authority = null): array
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        /** @var list<string> $queue */
        $queue = [];
        /** @var array<string, true> $queued */
        $queued = [];
        /** @var array<string, true> $entries */
        $entries = [];

        foreach ($dependencyIds as $dependencyId) {
            if (isset($queued[$dependencyId])) {
                continue;
            }
            $queued[$dependencyId] = true;
            $queue[] = $dependencyId;
        }

        for ($offset = 0; isset($queue[$offset]); ++$offset) {
            $dependencyId = $queue[$offset];

            foreach ($this->dependents[$dependencyId] ?? [] as $entry => $_) {
                $entry = (string) $entry;
                if (!isset($entries[$entry])) {
                    $entries[$entry] = true;
                    $this->invalidateChain($entry, $retirement);
                }

                if (!isset($queued[$entry])) {
                    $queued[$entry] = true;
                    $queue[] = $entry;
                }
            }
        }

        return array_map('strval', array_keys($entries));
    }

    /** @throws DelegatorException */
    public function apply(
        string $id,
        mixed $entry,
        ContainerInterface $container,
        ?object $authority = null,
    ): mixed {
        if (!isset($this->raw[$id])) {
            return $entry;
        }
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);

        $callables = $this->callables[$id] ?? null;
        if ($callables === null) {
            $revision = $this->revisions[$id] ?? 0;
            $lookupVersion = EntryLookupRevision::current();
            $refine = isset($this->refinableDependencies[$id]);
            $lookup = $this->lookup !== null && $refine
                ? new CallableDependencyLookup($this->lookup)
                : null;
            $standaloneDependencies = $this->lookup === null && $refine ? [] : null;
            $resolver = $lookup === null ? $this->callableResolver : new CallableResolver($lookup);
            $selection = ($this->selectionLookups)?->__invoke();
            $observed = [];
            $previous = $selection?->replace(function (string $dependency) use ($id, &$observed): void {
                $observed[$dependency] = true;
                // Publish the edge before the read. Mutations during selection
                // must invalidate both the callable and its pending result.
                $this->indexedDependencies[$id][$dependency] = true;
                $this->linkDependency($dependency, $id);
            });
            $publish = false;
            try {
                $callables = $this->resolveChain($id, $resolver, $standaloneDependencies);
                $publish = ($this->revisions[$id] ?? 0) === $revision && EntryLookupRevision::current() === $lookupVersion;
            } finally {
                $selection?->replace($previous);
                if (!$publish && $observed !== []) {
                    $this->indexDependencies($id, $this->registeredDependencies[$id] ?? []);
                }
            }
            if ($publish) {
                if ($lookup !== null) {
                    $dependencies = $lookup->dependencies;
                    // Exact string bindings can override the currently selected callable.
                    foreach ($this->raw[$id] as ['delegator' => $specification]) {
                        if (is_string($specification)) {
                            $dependencies[$specification] = true;
                        }
                    }
                    $this->indexDependencies($id, $dependencies);
                    if (count($callables) === 1 && $callables[0] === $this->raw[$id][0]['delegator']) {
                        // A native callable with its original route stays available.
                        $this->registeredDependencies[$id] = $dependencies;
                        unset($this->refinableDependencies[$id]);
                    }
                } elseif ($standaloneDependencies !== null || $observed !== []) {
                    $dependencies = $standaloneDependencies ?? $this->registeredDependencies[$id] ?? [];
                    $this->indexDependencies($id, $dependencies + $observed);
                }
                $this->callables[$id] = $callables;
            }
        }
        foreach ($callables as $callable) {
            try {
                // A qualified array needs native capture for direct invocation.
                // Keep the original cached route: a static closure does not own
                // the object receiver or preserve its retirement lifetime.
                $invoke = is_array($callable) && str_contains($callable[1], '::')
                    ? Closure::fromCallable($callable)
                    : $callable;
                $entry = $invoke($entry, $container);
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw DelegatorException::forEntry($id, $e);
            }
        }
        return $entry;
    }

    /** @param array<string, true> $dependencies */
    private function indexDependencies(string $id, array $dependencies): void
    {
        foreach (array_diff_key($this->indexedDependencies[$id] ?? [], $dependencies) as $dependency => $_) {
            unset($this->dependents[$dependency][$id]);
            if ($this->dependents[$dependency] === []) {
                unset($this->dependents[$dependency], $this->dependencyOrders[$dependency]);
            }
        }
        foreach ($dependencies as $dependency => $_) {
            $this->linkDependency($dependency, $id);
        }
        $this->indexedDependencies[$id] = $dependencies;
    }

    private function linkDependency(string|int $dependency, string $entry): void
    {
        $this->dependencyOrders[$dependency] ??= $this->dependencyOrder++;
        $this->dependents[$dependency][$entry] = true;
    }

    /**
     * @param array<string,true>|null $standaloneDependencies
     * @return list<callable>
     */
    private function resolveChain(
        string $id,
        CallableResolverInterface $resolver,
        ?array &$standaloneDependencies,
    ): array {
        $callables = [];
        foreach ($this->raw[$id] as ['delegator' => $delegator, 'dependencies' => $dependencies]) {
            try {
                if ($delegator instanceof Closure || (!is_string($delegator) && self::isNativeCallable($delegator))) {
                    $callables[] = $delegator;
                    continue;
                }
                $callables[] = $resolver->resolve($delegator);
                // The supplied executor can use the owner while resolving
                // even when it returns a native callable.
                if ($standaloneDependencies !== null) {
                    foreach ($dependencies as $dependencyId) {
                        $standaloneDependencies[$dependencyId] = true;
                    }
                }
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw DelegatorException::forEntry($id, $e);
            }
        }
        return $callables;
    }

    /** @return list<string> */
    private function dependencyIds(mixed $delegator): array
    {
        if (is_string($delegator)) {
            if ($delegator === '') {
                return [];
            }

            $ids = [$delegator => true];
            if (str_contains($delegator, '::')) {
                $nativeOwner = substr($delegator, 0, (int) strrpos($delegator, '::'));
                // Engine aliases can contain delimiters. A loaded native callable
                // bypasses container owner lookup, including the first prefix.
                $nativeRoute = (class_exists($nativeOwner, false) || interface_exists($nativeOwner, false))
                    && self::isNativeCallable($delegator);

                [$owner, $method] = explode('::', $delegator, 2);
                if ($owner !== '' && $method !== '') {
                    // The interface probe can autoload a class after the first probe failed.
                    // Qualified method shorthand resolves through the service receiver.
                    $isNativeStatic = $nativeRoute || ((class_exists($owner) || interface_exists($owner) || class_exists($owner, false))
                        && !str_contains($method, '::')
                        && self::isNativeCallable([$owner, $method]));
                    if (!$isNativeStatic || $this->lookup === null) {
                        // An unresolved class can later require a service lookup.
                        // A supplied standalone executor can also use that service
                        // when a native static route is already available.
                        $ids[$owner] = true;
                    }
                }
            }
            return array_map('strval', array_keys($ids));
        }

        if (is_array($delegator)
            && !self::isNativeCallable($delegator)
            && array_keys($delegator) === [0, 1]
            && is_string($delegator[0])
            && $delegator[0] !== ''
        ) {
            return [$delegator[0]];
        }

        return [];
    }

    /** @phpstan-assert-if-true callable $specification */
    private static function isNativeCallable(mixed $specification): bool
    {
        try {
            return is_callable($specification);
        } catch (Error $error) {
            if (!is_abstract_static_dispatch_error($error, __FILE__)) {
                throw $error;
            }
            return false;
        }
    }
}
