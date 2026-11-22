<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\CallableResolver;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\ExceptionInterface;
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
    /** @var array<string, list<mixed>> */
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

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
        private readonly ?ContainerInterface $lookup = null,
    ) {}

    /** @return PreparedDelegator */
    public function prepare(string $id, mixed $delegator): array
    {
        $delegator = DependencyConfiguration::normalizeDelegatorSpecification($delegator, $id);
        return ['delegator' => $delegator, 'dependencies' => self::dependencyIds($delegator)];
    }

    /** @param PreparedDelegator $prepared */
    public function register(string $id, array $prepared, MutationRetirement $retirement): void
    {
        $this->invalidate($id, $retirement);
        $delegator = $prepared['delegator'];
        $this->raw[$id][] = $delegator;
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

    public function invalidate(string $id, MutationRetirement $retirement): void
    {
        if (isset($this->callables[$id])) {
            $retirement->retain($this->callables[$id]);
            // A cold selection must be invalidated across callback reentry,
            // including when its next owner differs from the warmed route.
            if ($this->lookup !== null && isset($this->refinableDependencies[$id])) {
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

    /** @return non-negative-int */
    public function dependencyCount(): int
    {
        return count($this->dependents);
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
    public function invalidateDependencies(iterable $dependencyIds, MutationRetirement $retirement): array
    {
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
                    $this->invalidate($entry, $retirement);
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
    public function apply(string $id, mixed $entry, ContainerInterface $container): mixed
    {
        if (!isset($this->raw[$id])) {
            return $entry;
        }

        $callables = $this->callables[$id] ?? null;
        if ($callables === null) {
            $revision = $this->revisions[$id] ?? 0;
            $lookup = $this->lookup !== null && isset($this->refinableDependencies[$id])
                ? new CallableDependencyLookup($this->lookup)
                : null;
            $resolver = $lookup === null ? $this->callableResolver : new CallableResolver($lookup);
            $callables = $this->resolveChain($id, $resolver);
            if (($this->revisions[$id] ?? 0) === $revision) {
                if ($lookup !== null) {
                    $this->indexDependencies($id, $lookup->dependencies);
                    if (count($callables) === 1 && $callables[0] === $this->raw[$id][0]) {
                        // A native callable with its original route stays available.
                        // Any exact string binding still has precedence on re-resolution.
                        $this->registeredDependencies[$id] = $lookup->dependencies;
                        unset($this->refinableDependencies[$id]);
                    }
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

    /** @return list<callable> */
    private function resolveChain(string $id, CallableResolverInterface $resolver): array
    {
        $callables = [];
        foreach ($this->raw[$id] as $delegator) {
            try {
                $callables[] = self::normalize($delegator, $resolver);
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw DelegatorException::forEntry($id, $e);
            }
        }
        return $callables;
    }

    private static function normalize(mixed $delegator, CallableResolverInterface $resolver): callable
    {
        if ($delegator instanceof Closure) {
            return $delegator;
        }
        if (is_string($delegator)) {
            return $resolver->resolve($delegator);
        }
        if (is_callable($delegator)) {
            return $delegator;
        }
        return $resolver->resolve($delegator);
    }

    /** @return list<string> */
    private static function dependencyIds(mixed $delegator): array
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
                if ((class_exists($nativeOwner, false) || interface_exists($nativeOwner, false))
                    && is_callable($delegator)
                ) {
                    return array_map('strval', array_keys($ids));
                }

                [$owner, $method] = explode('::', $delegator, 2);
                if ($owner !== '' && $method !== '') {
                    // The interface probe can autoload a class after the first probe failed.
                    $isNativeStatic = (class_exists($owner) || interface_exists($owner) || class_exists($owner, false))
                        && is_callable([$owner, $method]);
                    if (!$isNativeStatic) {
                        // A class may be autoloaded only after registration. Track
                        // its owner conservatively so a later non-static method
                        // resolved through the container is invalidated correctly.
                        $ids[$owner] = true;
                    }
                }
            }
            return array_map('strval', array_keys($ids));
        }

        if (is_array($delegator)
            && !is_callable($delegator)
            && array_keys($delegator) === [0, 1]
            && is_string($delegator[0])
            && $delegator[0] !== ''
        ) {
            return [$delegator[0]];
        }

        return [];
    }
}
