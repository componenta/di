<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\ExternalContainerRegistry;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Internal\ProtectedServiceIds;
use Componenta\DI\Internal\Resolver\Entry\DefinitionWriter;
use Componenta\DI\Internal\Resolver\Entry\EntryResolverContext;
use Componenta\DI\Internal\RuntimeCollaboratorOwnership;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

/** PSR-11 container and fresh-resolution façade. */
final class Container implements
    ContainerInterface,
    FactoryInterface,
    CallableExecutorInterface,
    ProxyFactoryInterface
{
    private readonly EntryCache $cache;
    private readonly DelegatorRegistry $delegators;
    private ?ExternalContainerRegistry $externalContainers = null;
    private readonly CycleGuard $cycleGuard;
    private ?CycleGuard $lookupGuard = null;
    private readonly CycleGuard $resolvedEntryGuard;
    private readonly ProxyFactoryInterface $proxyFactory;
    private readonly CompositeResolver $resolver;
    private readonly DefinitionWriter $definitionWriter;
    private readonly object $runtimeAuthority;

    /** @param array<array-key, mixed> $bootstrapServices */
    public function __construct(
        EntryResolverInterface $resolver,
        private readonly AliasResolver $aliases,
        private readonly CallableExecutorInterface $callableExecutor,
        ?EntryCache $cache = null,
        ?DelegatorRegistry $delegators = null,
        ?ExternalContainerRegistry $externalContainers = null,
        ?CycleGuard $cycleGuard = null,
        ?ProxyFactoryInterface $proxyFactory = null,
        array $bootstrapServices = [],
    ) {
        $validatedAliasRevision = $aliases->validateProtectedRoutes();

        $core = [
            ContainerInterface::class => $this,
            FactoryInterface::class => $this,
            CallableInvokerInterface::class => $this,
            CallableResolverInterface::class => $this,
            CallableExecutorInterface::class => $this,
            ProxyFactoryInterface::class => $this,
            LazyObjectFactoryInterface::class => $this,
            VirtualProxyFactoryInterface::class => $this,
            self::class => $this,
        ];
        $validated = [];

        foreach ($bootstrapServices as $id => $service) {
            if (!is_string($id) || ($expected = ProtectedServiceIds::bootstrapType($id)) === null) {
                throw new InvalidConfigurationException(sprintf('Unsupported bootstrap service id "%s".', (string) $id));
            }
            if (!$service instanceof $expected) {
                throw new InvalidConfigurationException(sprintf(
                    'Bootstrap service "%s" must implement %s; got %s.',
                    $id,
                    $expected,
                    get_debug_type($service),
                ));
            }
            $validated[$id] = $service;
        }

        $this->resolver = $resolver instanceof CompositeResolver ? $resolver : new CompositeResolver($resolver);

        $this->cache = $cache ?? new EntryCache();
        $this->delegators = $delegators ?? new DelegatorRegistry($this);
        $this->externalContainers = $externalContainers;
        $this->cycleGuard = $cycleGuard ?? new CycleGuard();
        $this->resolvedEntryGuard = new CycleGuard();
        $this->proxyFactory = $proxyFactory ?? new ProxyFactory();

        $ownership = new RuntimeCollaboratorOwnership(
            $aliases,
            $this->cache,
            $this->delegators,
            $this->cycleGuard,
            $externalContainers,
            $validatedAliasRevision,
        );
        // Attach the root and its collaborators after loading helpers, before publishing core values.
        $this->definitionWriter = $this->resolver->attach($ownership);
        $this->runtimeAuthority = $ownership->authority;

        // Supplied core seeds can reenter from destructors. Publish the complete
        // core only after every collaborator is ready, then release old values.
        $replacedCore = [];
        foreach ($core + $validated as $id => $service) {
            if ($this->cache->tryGetBase($id, $previous)) {
                $replacedCore[] = $previous;
            }
            $this->cache->putBase($id, $service);
        }
        unset($previous, $replacedCore);
    }

    public function get(string $id): mixed
    {
        try {
            $entryId = null;
            if ($this->externalContainers !== null) {
                $entryId = $this->aliases->resolve($id);
                if (!ProtectedServiceIds::contains($id) && !ProtectedServiceIds::contains($entryId)) {
                    $externalGuard = "\0external:" . $id;
                    $lookupGuard = $this->lookupGuard ??= new CycleGuard();
                    $lookupGuard->enter($externalGuard);
                    try {
                        $external = $this->externalContainers->findOwning(
                            $id,
                            fn(string $dependencyId): bool => !$this->isProtectedDependency($dependencyId),
                        );
                        // External has() may retarget aliases before local resolution.
                        $entryId = $this->aliases->resolve($id);
                        if ($external !== null && !ProtectedServiceIds::contains($entryId)) {
                            return $external->get($id);
                        }
                    } finally {
                        $lookupGuard->leave($externalGuard);
                    }
                }
            }

            if ($this->cache->tryGetResolved($id, $entry)) {
                return $entry;
            }

            $entryId ??= $this->aliases->resolve($id);
            $this->resolvedEntryGuard->enterShared($id);
            try {
                if ($this->tryResolveAndStore($id, $entryId, $entry)) {
                    return $entry;
                }
            } finally {
                $this->resolvedEntryGuard->leaveShared($id);
            }
        } catch (NotFoundExceptionInterface $e) {
            throw ResolutionException::forService($id, $e);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }

        throw NotFoundException::forService($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function getAs(string $id, string $type): object
    {
        return self::requireEntryType($id, $this->get($id), $type);
    }

    private function tryResolveAndStore(string $requestedId, string $entryId, mixed &$entry): bool
    {
        $requestedRevision = $this->cache->resolvedRevision($requestedId);
        $baseRevision = $this->cache->baseRevision($entryId);
        if (!$this->cache->tryGetBase($entryId, $entry)) {
            // Aliases share source creation, but each requested id owns its
            // delegator pipeline and may read another id once the source exists.
            $this->cycleGuard->enterShared($entryId);
            try {
                if (!$this->resolver->tryResolveShared($entryId, $entry)) {
                    return false;
                }
                if ($this->cache->baseRevision($entryId) === $baseRevision) {
                    $this->cache->putBase($entryId, $entry);
                    $baseRevision = $this->cache->baseRevision($entryId);
                }
            } finally {
                $this->cycleGuard->leaveShared($entryId);
            }
        }

        $entry = $this->delegators->apply($requestedId, $entry, $this);
        // A factory or delegator may mutate bindings, including while suspended.
        // Its in-flight result must not repopulate an invalidated shared entry.
        if ($this->cache->resolvedRevision($requestedId) === $requestedRevision
            && $this->cache->baseRevision($entryId) === $baseRevision
        ) {
            $this->cache->putResolved($requestedId, $entryId, $entry);
        }

        return true;
    }

    public function has(string $id): bool
    {
        try {
            $entryId = $this->aliases->resolve($id);
            // A callback may retarget this alias to an existing core service.
            // Its availability is known without entering another lookup cycle.
            if (ProtectedServiceIds::contains($entryId) && $this->cache->tryGetBase($entryId, $base)) {
                return true;
            }

            $guardId = "\0has:" . $id;
            $lookupGuard = $this->lookupGuard ??= new CycleGuard();
            $lookupGuard->enter($guardId);
            try {
                if ($this->externalContainers !== null
                    && !ProtectedServiceIds::contains($id)
                    && !ProtectedServiceIds::contains($entryId)
                ) {
                    $external = $this->externalContainers->findOwning(
                        $id,
                        fn(string $dependencyId): bool => !$this->isProtectedDependency($dependencyId),
                    );
                    // External has() may retarget aliases before local availability checks.
                    $entryId = $this->aliases->resolve($id);
                    if ($external !== null && !ProtectedServiceIds::contains($entryId)) {
                        return true;
                    }
                }
                if ($this->cache->tryGetResolved($id, $resolved)) {
                    return true;
                }
                if ($this->cache->tryGetBase($entryId, $base)) {
                    return true;
                }
                return $this->resolver->can($entryId);
            } finally {
                $lookupGuard->leave($guardId);
            }
        } catch (Throwable) {
            return false;
        }
    }

    public function set(string $id, mixed $entry): void
    {
        self::assertMutableId($id, 'entry');
        $canonical = $this->aliases->resolve($id);
        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf('Cannot replace protected DI id "%s".', $id));
        }

        $binding = null;
        if ($entry instanceof DefinitionInterface) {
            try {
                $binding = $this->definitionWriter->prepare($canonical, $entry);
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new InvalidConfigurationException(
                    sprintf('Failed to register a definition for DI id "%s".', $id),
                    previous: $e,
                );
            }
        }

        $retirement = new MutationRetirement($canonical);
        // Registration and helper autoloading may retarget deferred dependencies.
        $affected = $this->deferredDependenciesResolvingTo($canonical);
        // During admission a nested alias may hide the originally selected owner.
        if (!$this->aliases->has($canonical)) {
            if ($binding !== null) {
                $this->definitionWriter->publish($binding, $retirement);
                $this->cache->removeBase($canonical, $retirement);
            } else {
                $this->cache->replaceBase($canonical, $entry, $retirement);
            }
        }
        $this->invalidate($id, $retirement, $canonical);
        $this->invalidateDeferredDelegators($affected, $retirement);
        $retirement->release();
    }

    /**
     * @param non-empty-string $entry
     * @param array<string|int, mixed> $params
     */
    public function make(string $entry, array $params = []): object
    {
        try {
            $resolved = $this->aliases->resolve($entry);
            $this->cycleGuard->enter($resolved);

            try {
                $instance = $this->resolver->resolve(
                    $resolved,
                    EntryResolverContext::for($this->resolver, $params),
                );

                if (!is_object($instance)) {
                    throw ResolutionException::forNonObject($resolved, get_debug_type($instance));
                }

                return $instance;
            } finally {
                $this->cycleGuard->leave($resolved);
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (NotFoundExceptionInterface $e) {
            throw NotFoundException::forService($entry, $e);
        } catch (Throwable $e) {
            throw ResolutionException::forService($entry, $e);
        }
    }

    /**
     * @template T of object
     * @param non-empty-string $entry
     * @param class-string<T> $type
     * @param array<string|int, mixed> $params
     * @return T
     */
    public function makeAs(string $entry, string $type, array $params = []): object
    {
        return self::requireEntryType($entry, $this->make($entry, $params), $type);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private static function requireEntryType(string $id, mixed $value, string $type): object
    {
        if (!$value instanceof $type) {
            $actual = get_debug_type($value);
            throw new ResolutionException(
                sprintf('Service "%s" resolved to %s, expected %s.', $id, $actual, $type),
                serviceId: $id,
                actualType: $actual,
            );
        }
        return $value;
    }

    /** @param array<string|int, mixed> $params */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->callableExecutor->call($callable, $params);
    }

    public function resolve(mixed $callable): callable
    {
        return $this->callableExecutor->resolve($callable);
    }

    public function makeLazy(string $class, callable $initializer): object
    {
        return $this->proxyFactory->makeLazy($class, $initializer);
    }

    public function makeProxy(string $class, callable $factory): object
    {
        return $this->proxyFactory->makeProxy($class, $factory);
    }

    public function addContainer(ContainerInterface $container): void
    {
        if ($container === $this) {
            throw new InvalidConfigurationException('The container cannot delegate lookups to itself.');
        }
        if ($this->externalContainers !== null && $this->externalContainers->contains($container)) {
            return;
        }

        $retirement = new MutationRetirement(null);
        // Helper autoloaders may publish nested registrations before inspection.
        $externalContainers = $this->externalContainers ?? new ExternalContainerRegistry();
        try {
            $affected = $this->deferredDependenciesTakenOverBy($container);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(
                'Failed to inspect an external container while registering it.',
                previous: $e,
            );
        }

        if ($this->externalContainers !== null && $this->externalContainers->contains($container)) {
            return;
        }

        ($this->externalContainers ??= $externalContainers)->register($container);
        $this->invalidateDeferredDelegators($affected, $retirement);
        $retirement->release();
    }

    /**
     * Refreshes delegators that can read externally changed entries.
     * The provider publishes its changes before reporting the affected IDs.
     */
    public function notifyExternalEntriesChanged(ContainerInterface $provider, string ...$ids): void
    {
        $external = $this->externalContainers;
        if ($external === null || !$external->contains($provider)) {
            throw new InvalidConfigurationException('External entry changes require a registered provider.');
        }
        $affected = [];
        foreach ($ids as $id) {
            self::assertMutableId($id, 'external entry');
            if ($this->delegators->dependsOn($id) && !$this->isProtectedDependency($id)) {
                $affected[] = $id;
            }
        }
        if ($affected === []) {
            return;
        }

        $retirement = new MutationRetirement(null);
        $external->noteOwnershipChanges($affected);
        $this->invalidateDeferredDelegators($affected, $retirement);
        $retirement->release();
    }

    public function alias(string $alias, string $target): void
    {
        if (ProtectedServiceIds::contains($alias)) {
            throw new InvalidConfigurationException(sprintf('Cannot replace protected DI alias "%s".', $alias));
        }

        $retirement = new MutationRetirement($alias);
        // Only this edge and the aliases leading to it can change destination.
        do {
            $revision = $this->aliases->revision;
            $previousTarget = $this->aliases->resolve($alias);
            $canonical = $this->aliases->validateTarget($alias, $target);
            $affected = $this->aliases->affectedIds($alias);
            if (ProtectedServiceIds::contains($canonical)
                && ($delegatorId = $this->delegators->firstDelegatedEntryAmong($affected)) !== null
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Cannot retarget delegator for id "%s" to protected DI id "%s".',
                    $delegatorId,
                    $canonical,
                ));
            }
            $changedDependencies = $canonical === $previousTarget ? [] : $this->delegators->dependenciesAmong($affected);
            $hidesCanonical = !$this->aliases->has($alias);
        } while (!$this->aliases->publishValidated($alias, $target, $revision, $this->runtimeAuthority, $affected));

        if ($hidesCanonical) {
            $this->definitionWriter->retire($alias, $retirement);
            $this->cache->removeBase($alias, $retirement);
        }
        if ($canonical !== $previousTarget) {
            foreach ($affected as $changedId => $_) {
                $this->invalidateRequested((string) $changedId, $previousTarget, $retirement);
            }
        }
        $this->invalidateDeferredDelegators($changedDependencies, $retirement);
        $retirement->release();
    }

    /** @param callable|string|array{0: object|string, 1: string} $delegator */
    public function delegator(string $id, callable|string|array $delegator): void
    {
        self::assertMutableId($id, 'delegator');
        $canonical = $this->aliases->resolve($id);
        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf('Cannot decorate protected DI id "%s".', $id));
        }

        $retirement = new MutationRetirement($id);
        try {
            $prepared = $this->delegators->prepare($id, $delegator);
            // Preparing a callable may autoload code that retargets this alias.
            $canonical = $this->aliases->resolve($id);
            if (ProtectedServiceIds::contains($canonical)) {
                throw new InvalidConfigurationException(sprintf('Cannot decorate protected DI id "%s".', $id));
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to register a delegator for DI id "%s".', $id),
                previous: $e,
            );
        }
        $this->delegators->register($id, $prepared, $retirement);
        $this->invalidateRequested($id, $canonical, $retirement);
        $this->invalidateDeferredDelegators([$id], $retirement);
        $retirement->release();
    }

    private static function assertMutableId(string $id, string $kind): void
    {
        if ($id === '') {
            throw new InvalidConfigurationException(sprintf('Cannot register %s with an empty DI id.', $kind));
        }
    }

    private function invalidate(string $id, MutationRetirement $retirement, ?string $knownCanonical = null): void
    {
        try {
            $canonical = $knownCanonical ?? $this->aliases->resolve($id);
        } catch (Throwable) {
            $canonical = $id;
        }

        $this->cache->invalidate($id, $canonical, $retirement);
        $this->delegators->invalidate($id, $retirement);
        if ($canonical !== $id) {
            $this->delegators->invalidate($canonical, $retirement);
        }
    }

    private function invalidateRequested(string $id, string $canonical, MutationRetirement $retirement): void
    {
        $this->cache->invalidateRequested($id, $canonical, $retirement);
        $this->delegators->invalidate($id, $retirement);
    }

    /** @return list<string> */
    private function deferredDependenciesResolvingTo(string $canonical): array
    {
        $dependencyCount = $this->delegators->dependencyCount();
        if ($dependencyCount === 0 || $this->aliases->has($canonical)) {
            return [];
        }

        $family = $this->aliases->affectedIds($canonical, $dependencyCount);
        if ($family !== null) {
            return $this->delegators->dependenciesAmong($family);
        }

        // A binding can serve many aliases while only a few delegators depend
        // on them. Bound the reverse walk by the size of the dependency index.
        $dependencies = [];
        foreach ($this->delegators->deferredDependencies() as $dependency) {
            if ($this->aliases->resolve($dependency) === $canonical) {
                $dependencies[] = $dependency;
            }
        }
        return $dependencies;
    }

    /** @return list<string> */
    private function deferredDependenciesTakenOverBy(ContainerInterface $container): array
    {
        $candidates = [];
        $inspected = [];
        $pending = [];
        $mayInspect = fn(string $id): bool => !$this->isProtectedDependency($id);
        do {
            $ownershipRevision = $this->externalContainers->ownershipRevision ?? 0;
            foreach ($this->delegators->deferredDependencies() as $dependencyId) {
                $dependencyRevision = $this->externalContainers?->ownershipRevisionFor($dependencyId) ?? 0;
                $previousRevision = $inspected[$dependencyId] ?? null;
                if ($previousRevision === $dependencyRevision && !isset($pending[$dependencyId])) {
                    continue;
                }
                if ($previousRevision !== null && $previousRevision !== $dependencyRevision) {
                    unset($candidates[$dependencyId]);
                }
                $inspected[$dependencyId] = $dependencyRevision;
                if ($this->isProtectedDependency($dependencyId)) {
                    $pending[$dependencyId] = true;
                    continue;
                }
                unset($pending[$dependencyId]);
                do {
                    $revision = $this->externalContainers->revision ?? 0;
                    $owner = $this->externalContainers?->findOwning($dependencyId, $mayInspect);
                    if ($this->externalContainers !== null && $this->externalContainers->contains($container)) {
                        return [];
                    }
                } while ($revision !== ($this->externalContainers->revision ?? 0)
                    && !$this->isProtectedDependency($dependencyId));
                if ($this->isProtectedDependency($dependencyId)) {
                    $pending[$dependencyId] = true;
                    continue;
                }
                if ($owner === null && $container->has($dependencyId)) {
                    $candidates[$dependencyId] = true;
                }
                if ($this->externalContainers !== null && $this->externalContainers->contains($container)) {
                    return [];
                }
            }

            // Keep candidate answers, but prove ownership against the completed
            // registry. Existing-owner callbacks can publish further containers.
            $dependencies = [];
            $protectedCandidates = [];
            $revision = $this->externalContainers->revision ?? 0;
            foreach ($candidates as $id => $_) {
                $dependencyId = (string) $id;
                if (!$this->delegators->dependsOn($dependencyId)) {
                    continue;
                }
                if ($this->isProtectedDependency($dependencyId)) {
                    $protectedCandidates[$dependencyId] = true;
                    continue;
                }
                $owner = $this->externalContainers?->findOwning($dependencyId, $mayInspect);
                if ($this->externalContainers !== null && $this->externalContainers->contains($container)) {
                    return [];
                }
                if ($this->isProtectedDependency($dependencyId)) {
                    $protectedCandidates[$dependencyId] = true;
                    continue;
                }
                if ($owner === null) {
                    $dependencies[] = $dependencyId;
                }
            }
            // A later callback can make a skipped alias externally eligible.
            // Revisit only its unfinished inspection/proof, never a candidate answer.
            $retryProtected = false;
            foreach ($pending + $protectedCandidates as $dependencyId => $_) {
                if (!$this->isProtectedDependency((string) $dependencyId)) {
                    $retryProtected = true;
                    break;
                }
            }
            // Either phase can also introduce new deferred dependency IDs.
        } while ($retryProtected
            || $revision !== ($this->externalContainers->revision ?? 0)
            || $ownershipRevision !== ($this->externalContainers->ownershipRevision ?? 0)
            || array_diff($this->delegators->deferredDependencies(), array_keys($inspected)) !== []);

        // A later proof may also protect a dependency already selected above.
        return array_values(array_filter(
            $dependencies,
            fn(string $dependencyId): bool => !$this->isProtectedDependency($dependencyId),
        ));
    }

    /**
     * Reentrant ownership callbacks can retarget aliases between queries.
     * @phpstan-impure
     */
    private function isProtectedDependency(string $id): bool
    {
        return ProtectedServiceIds::contains($id)
            || ProtectedServiceIds::contains($this->aliases->resolve($id));
    }

    /** @param iterable<string> $dependencyIds */
    private function invalidateDeferredDelegators(iterable $dependencyIds, MutationRetirement $retirement): void
    {
        foreach ($this->delegators->invalidateDependencies($dependencyIds, $retirement) as $entryId) {
            try {
                $canonical = $this->aliases->resolve($entryId);
            } catch (Throwable) {
                $canonical = $entryId;
            }
            $this->invalidateRequested($entryId, $canonical, $retirement);
        }
    }
}
