<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerEntryOperations;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\EntryLookupRevision;
use Componenta\DI\Internal\ExternalContainerRegistry;
use Componenta\DI\Internal\ExternalDependencyInspection;
use Componenta\DI\Internal\ExternalLookupProbes;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Internal\ProtectedServiceIds;
use Componenta\DI\Internal\Resolver\Entry\AttachedEntryBindings;
use Componenta\DI\Internal\Resolver\Entry\CompositeAttachment;
use Componenta\DI\Internal\RuntimeCollaboratorOwnership;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

/** PSR-11 container and fresh-resolution façade. */
final class Container extends ContainerEntryOperations implements
    ContainerInterface,
    FactoryInterface,
    CallableExecutorInterface,
    ProxyFactoryInterface
{
    private readonly AliasResolver $aliases;
    private readonly CallableExecutorInterface $callableExecutor;
    private readonly EntryCache $cache;
    private readonly DelegatorRegistry $delegators;
    private ?ExternalContainerRegistry $externalContainers = null;
    private readonly CycleGuard $cycleGuard;
    private ?CycleGuard $lookupGuard = null;
    private ?ExternalLookupProbes $providerProbes = null;
    private readonly CycleGuard $resolvedEntryGuard;
    private readonly ProxyFactoryInterface $proxyFactory;
    private readonly AttachedEntryBindings $resolver;
    private readonly object $runtimeAuthority;

    /**
     * @param array<string,string> $aliases
     * @param array<array-key,mixed> $services
     */
    public function __construct(
        EntryResolverInterface $resolver,
        CallableExecutorInterface $callableExecutor,
        array $aliases = [],
        array $services = [],
        ?ProxyFactoryInterface $proxyFactory = null,
    ) {
        $routes = new AliasResolver($aliases);
        $cache = new EntryCache();
        foreach ($services as $id => $service) {
            $id = (string) $id;
            self::assertMutableId($id, 'service');
            $canonical = $routes->resolve($id);
            if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
                throw new InvalidConfigurationException(sprintf('Cannot register a service for protected DI id "%s".', $canonical));
            }
            $cache->putBase($canonical, $service);
        }
        $this->assemble($resolver, $routes, $callableExecutor, cache: $cache, proxyFactory: $proxyFactory);
    }

    /** @param array<array-key,mixed> $bootstrapServices */
    protected function assemble(
        EntryResolverInterface $resolver,
        AliasResolver $aliases,
        CallableExecutorInterface $callableExecutor,
        ?EntryCache $cache = null,
        ?DelegatorRegistry $delegators = null,
        ?ExternalContainerRegistry $externalContainers = null,
        ?CycleGuard $cycleGuard = null,
        ?ProxyFactoryInterface $proxyFactory = null,
        array $bootstrapServices = [],
    ): void {
        $this->aliases = $aliases;
        $this->callableExecutor = $callableExecutor;
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

        $root = $resolver instanceof CompositeResolver ? $resolver : new CompositeResolver($resolver);

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
        $this->resolver = CompositeAttachment::toContainer($root, $ownership);
        $this->runtimeAuthority = $ownership->authority;

        // Supplied core seeds can reenter from destructors. Publish the complete
        // core only after every collaborator is ready, then release old values.
        $replacedCore = [];
        foreach ($core + $validated as $id => $service) {
            if ($this->cache->tryGetBase($id, $previous)) {
                $replacedCore[] = $previous;
            }
            $this->cache->putBase($id, $service, $this->runtimeAuthority);
        }
        unset($previous, $replacedCore);
    }

    public function get(string $id): mixed
    {
        try {
            if ($this->externalContainers === null && $this->cache->tryGetResolved($id, $entry)) {
                $this->providerProbes?->trackLookup($id);
                return $entry;
            }
        } catch (Throwable $e) {
            throw self::entryResolutionFailure($id, $e);
        }

        return $this->getEntry($id, requireResolvable: false);
    }

    protected function getEntry(string $id, bool $requireResolvable): mixed
    {
        try {
            $this->providerProbes?->trackLookup($id);
            $entryId = null;
            if ($this->externalContainers !== null) {
                $entryId = $this->aliases->resolve($id);
                if (!ProtectedServiceIds::contains($id) && !ProtectedServiceIds::contains($entryId)) {
                    $externalGuard = "\0external:" . $id;
                    $lookupGuard = $this->lookupGuard ??= new CycleGuard();
                    $lookupGuard->assertInactive($externalGuard);
                    $probes = $this->providerProbes ??= new ExternalLookupProbes();
                    $external = $this->externalContainers->findOwning(
                        $id,
                        fn(string $dependencyId): bool => !$this->isProtectedDependency($dependencyId),
                        $requireResolvable,
                        $probes,
                    );
                    // External has() may retarget aliases before local resolution.
                    $entryId = $this->aliases->resolve($id);
                    if ($external !== null && !ProtectedServiceIds::contains($entryId)) {
                        $lookupGuard->enter($externalGuard);
                        try {
                            return $probes->retrieve($external, $id, $requireResolvable);
                        } finally {
                            $lookupGuard->leave($externalGuard);
                        }
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
        } catch (Throwable $e) {
            throw self::entryResolutionFailure($id, $e);
        }

        throw NotFoundException::forService($id);
    }

    private static function entryResolutionFailure(string $id, Throwable $error): ExceptionInterface
    {
        return $error instanceof ExceptionInterface && !$error instanceof NotFoundExceptionInterface
            ? $error
            : ResolutionException::forService($id, $error);
    }

    private function tryResolveAndStore(string $requestedId, string $entryId, mixed &$entry): bool
    {
        $requestedRevision = $this->cache->resolvedRevision($requestedId);
        $baseRevision = $this->cache->baseRevision($entryId);
        $lookupRevision = EntryLookupRevision::current();
        if (!$this->cache->tryGetBase($entryId, $entry)) {
            // Aliases share source creation, but each requested id owns its
            // delegator pipeline and may read another id once the source exists.
            $this->cycleGuard->enterShared($entryId, $this->runtimeAuthority);
            try {
                $aliasRevision = $this->aliases->revision;
                $selected = $this->resolver->selectShared(
                    $entryId,
                    fn(): bool => $this->cache->tryGetBase($entryId, $published),
                );
                // Selection may publish a binding or retire the canonical ID by aliasing it.
                // Only a reachable canonical ID can adopt a new base revision.
                $selectedBaseRevision = $this->cache->baseRevision($entryId);
                if ($selectedBaseRevision !== $baseRevision && !$this->aliases->has($entryId)) {
                    $baseRevision = $selectedBaseRevision;
                }
                if ($this->aliases->revision === $aliasRevision || $this->aliases->resolve($requestedId) === $entryId) {
                    $requestedRevision = $this->cache->resolvedRevision($requestedId);
                }
                if (!$this->cache->tryGetBase($entryId, $entry)) {
                    if ($selected === null) {
                        return false;
                    }
                    $entry = $this->resolver->resolveSelectedShared($entryId, $selected);
                    if ($this->cache->baseRevision($entryId) === $baseRevision && EntryLookupRevision::current() === $lookupRevision) {
                        $this->cache->putBase($entryId, $entry, $this->runtimeAuthority);
                        $baseRevision = $this->cache->baseRevision($entryId);
                    }
                }
            } finally {
                $this->cycleGuard->leaveShared($entryId, $this->runtimeAuthority);
            }
        }

        $entry = $this->delegators->apply($requestedId, $entry, $this, $this->runtimeAuthority);
        // A factory or delegator may mutate bindings, including while suspended.
        // Its in-flight result must not repopulate an invalidated shared entry.
        // A nested lookup can also have selected a temporary route around an active provider.
        if ($this->cache->resolvedRevision($requestedId) === $requestedRevision
            && $this->cache->baseRevision($entryId) === $baseRevision
            && EntryLookupRevision::current() === $lookupRevision
        ) {
            $this->cache->putResolved($requestedId, $entryId, $entry, $this->runtimeAuthority);
        }

        return true;
    }

    /** @throws ExceptionInterface If availability could not be established. */
    public function has(string $id): bool
    {
        return $this->hasEntry($id, requireResolvable: false);
    }

    protected function hasEntry(string $id, bool $requireResolvable): bool
    {
        try {
            $this->providerProbes?->trackLookup($id);
            $entryId = $this->aliases->resolve($id);
            // A callback may retarget this alias to an existing core service.
            // Its availability is known without entering another lookup cycle.
            if (ProtectedServiceIds::contains($entryId) && $this->cache->tryGetBase($entryId, $base)) {
                return true;
            }

            $localGuard = "\0has:" . $id;
            if ($this->externalContainers !== null
                && !ProtectedServiceIds::contains($id)
                && !ProtectedServiceIds::contains($entryId)
            ) {
                $lookupGuard = $this->lookupGuard ??= new CycleGuard();
                if (!$lookupGuard->contains($localGuard)) {
                    $external = $this->externalContainers->findOwning(
                        $id,
                        fn(string $dependencyId): bool => !$this->isProtectedDependency($dependencyId),
                        $requireResolvable,
                        $this->providerProbes ??= new ExternalLookupProbes(),
                    );
                    // External has() may retarget aliases before local availability checks.
                    $entryId = $this->aliases->resolve($id);
                    if ($external !== null && !ProtectedServiceIds::contains($entryId)) {
                        return true;
                    }
                }
            }
            if ($this->cache->tryGetResolved($id, $resolved) || $this->cache->tryGetBase($entryId, $base)) {
                return true;
            }

            $lookupGuard = $this->lookupGuard ??= new CycleGuard();
            $lookupGuard->enter($localGuard);
            try {
                $hasSharedValue = fn(): bool => $this->cache->tryGetBase($entryId, $published);
                $available = $requireResolvable
                    ? $this->resolver->can($entryId, $hasSharedValue)
                    : $this->resolver->knows($entryId, $hasSharedValue);
                return $available || $hasSharedValue();
            } finally {
                $lookupGuard->leave($localGuard);
            }
        } catch (Throwable $e) {
            if ($requireResolvable) {
                return false;
            }
            throw self::entryResolutionFailure($id, $e);
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
                $binding = $this->resolver->prepare($canonical, $entry);
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
        // A nested alias can retire this binding before its validation completes.
        if ($this->aliases->has($canonical)) {
            return;
        }

        // Registration and helper autoloading may retarget deferred dependencies.
        $affected = $this->deferredDependenciesResolvingTo($canonical);
        // Invalidate the requested pipeline only if it still reads the selected binding.
        $affectedId = $this->aliases->resolve($id) === $canonical ? $id : $canonical;
        if ($binding !== null) {
            $this->resolver->publish($binding, $retirement);
            $this->cache->removeBase($canonical, $retirement, $this->runtimeAuthority);
        } else {
            $this->cache->replaceBase($canonical, $entry, $retirement, $this->runtimeAuthority);
        }
        $this->invalidate($affectedId, $canonical, $retirement);
        $this->invalidateDeferredDelegators($affected, $retirement);
        $this->providerProbes?->invalidate();
        $retirement->release();
    }

    /**
     * @param non-empty-string $entry
     * @param array<string|int, mixed> $params
     */
    public function make(string $entry, array $params = []): object
    {
        return $this->makeEntry($entry, $params);
    }

    /** @param array<string|int,mixed>|EntryParameters $params */
    protected function makeEntry(string $entry, array|EntryParameters $params): object
    {
        try {
            $resolved = $this->aliases->resolve($entry);
            $this->cycleGuard->enter($resolved, $this->runtimeAuthority);

            try {
                $instance = $this->resolver->resolve($resolved, $params);

                if (!is_object($instance)) {
                    throw ResolutionException::forNonObject($resolved, get_debug_type($instance));
                }

                return $instance;
            } finally {
                $this->cycleGuard->leave($resolved, $this->runtimeAuthority);
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (NotFoundExceptionInterface $e) {
            throw NotFoundException::forService($entry, $e);
        } catch (Throwable $e) {
            throw ResolutionException::forService($entry, $e);
        }
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
            $affected = new ExternalDependencyInspection(
                $this->delegators,
                fn(): ?ExternalContainerRegistry => $this->externalContainers,
                fn(string $id): bool => !$this->isProtectedDependency($id),
                $this->providerProbes ??= new ExternalLookupProbes(),
            )->takenOverBy($container);
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

        ($this->externalContainers ??= $externalContainers)->register($container, $this->runtimeAuthority);
        $this->invalidateDeferredDelegators($affected, $retirement);
        $this->providerProbes?->invalidate(appending: true);
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
        $externalIds = [];
        foreach ($ids as $id) {
            self::assertMutableId($id, 'external entry');
            if (!$this->isProtectedDependency($id)) {
                $externalIds[] = $id;
                if ($this->delegators->dependsOn($id)) {
                    $affected[] = $id;
                }
            }
        }
        if ($externalIds !== []) {
            $this->providerProbes?->notify($externalIds);
        }
        if ($affected === []) {
            return;
        }

        $retirement = new MutationRetirement(null);
        $external->noteOwnershipChanges($affected, $this->runtimeAuthority);
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
            $this->resolver->retire($alias, $retirement);
            $this->cache->removeBase($alias, $retirement, $this->runtimeAuthority);
        }
        if ($canonical !== $previousTarget) {
            foreach ($affected as $changedId => $_) {
                $this->invalidateRequested((string) $changedId, $previousTarget, $retirement);
            }
        }
        $this->invalidateDeferredDelegators($changedDependencies, $retirement);
        $this->providerProbes?->invalidate();
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
        $this->delegators->register($id, $prepared, $retirement, $this->runtimeAuthority);
        $this->invalidateRequested($id, $canonical, $retirement);
        $this->invalidateDeferredDelegators([$id], $retirement);
        $this->providerProbes?->invalidate();
        $retirement->release();
    }

    private static function assertMutableId(string $id, string $kind): void
    {
        if ($id === '') {
            throw new InvalidConfigurationException(sprintf('Cannot register %s with an empty DI id.', $kind));
        }
    }

    private function invalidate(string $id, string $canonical, MutationRetirement $retirement): void
    {
        $this->cache->invalidate($id, $canonical, $retirement, $this->runtimeAuthority);
        $this->delegators->invalidate($id, $retirement, $this->runtimeAuthority);
        if ($canonical !== $id) {
            $this->delegators->invalidate($canonical, $retirement, $this->runtimeAuthority);
        }
    }

    private function invalidateRequested(string $id, string $canonical, MutationRetirement $retirement): void
    {
        $this->cache->invalidateRequested($id, $canonical, $retirement, $this->runtimeAuthority);
        $this->delegators->invalidate($id, $retirement, $this->runtimeAuthority);
    }

    /** @return list<string> */
    private function deferredDependenciesResolvingTo(string $canonical): array
    {
        $dependencyCount = $this->delegators->dependencyCount;
        if ($dependencyCount === 0) {
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
        foreach ($this->delegators->invalidateDependencies($dependencyIds, $retirement, $this->runtimeAuthority) as $entryId) {
            try {
                $canonical = $this->aliases->resolve($entryId);
            } catch (Throwable) {
                $canonical = $entryId;
            }
            $this->invalidateRequested($entryId, $canonical, $retirement);
        }
    }

    private function __clone() {}
}
