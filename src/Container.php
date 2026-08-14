<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\DefinitionAwareResolverInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * PSR-11 dependency injection container.
 *
 * The container itself is a thin façade: each concern lives in its own
 * collaborator so responsibilities stay sharp and testable.
 *
 *  - {@see EntryResolverInterface}        - chain that actually builds entries.
 *  - {@see AliasResolver}                 - internal alias -> canonical id resolution.
 *  - {@see CallableExecutorInterface}     - resolve-and-invoke pipeline.
 *  - {@see ProxyFactoryInterface}         - produces lazy objects / virtual proxies.
 *  - {@see EntryCache}                    - two-tier base/decorated cache.
 *  - {@see DelegatorRegistry}             - decorator chain per entry.
 *  - {@see ExternalContainerRegistry}     - delegated PSR-11 containers.
 *  - {@see CycleGuard}                    - circular-dependency detection.
 */
final class Container implements
    ContainerInterface,
    FactoryInterface,
    CallableInvokerInterface,
    ProxyFactoryInterface
{
    private readonly EntryCache $cache;

    private readonly DelegatorRegistry $delegators;

    private ?ExternalContainerRegistry $externalContainers = null;

    private readonly CycleGuard $cycleGuard;

    private readonly ProxyFactoryInterface $proxyFactory;

    /**
     * Collaborators are wired via the constructor - no post-injection.
     *
     * Internal state holders are optional so tests and bespoke bootstrap code
     * can plug in replacements. The external-container registry remains null
     * until an external container is actually registered.
     *
     * @param array<array-key, mixed> $bootstrapServices
     */
    public function __construct(
        private readonly EntryResolverInterface    $resolver,
        private readonly AliasResolver             $aliases,
        private readonly CallableExecutorInterface $callableExecutor,
        ?EntryCache $cache = null,
        ?DelegatorRegistry $delegators = null,
        ?ExternalContainerRegistry $externalContainers = null,
        ?CycleGuard $cycleGuard = null,
        ?ProxyFactoryInterface $proxyFactory = null,
        array $bootstrapServices = [],
    ) {
        $coreServices = [
            ContainerInterface::class => $this,
            FactoryInterface::class => $this,
            CallableInvokerInterface::class => $this,
            ProxyFactoryInterface::class => $this,
            LazyObjectFactoryInterface::class => $this,
            VirtualProxyFactoryInterface::class => $this,
            self::class => $this,
            CallableResolverInterface::class => $this->callableExecutor,
            CallableExecutorInterface::class => $this->callableExecutor,
        ];
        $validatedBootstrapServices = [];

        foreach ($bootstrapServices as $id => $service) {
            if (!is_string($id) || ($expected = ProtectedServiceIds::bootstrapType($id)) === null) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported bootstrap service id "%s".',
                    (string) $id,
                ));
            }

            if (!$service instanceof $expected) {
                throw new InvalidConfigurationException(sprintf(
                    'Bootstrap service "%s" must implement %s; got %s.',
                    $id,
                    $expected,
                    get_debug_type($service),
                ));
            }

            $validatedBootstrapServices[$id] = $service;
        }

        if ($cache === null) {
            $this->cache = new EntryCache(
                $coreServices + $validatedBootstrapServices,
            );
        } else {
            $this->cache = $cache;

            foreach ($coreServices as $id => $service) {
                $this->cache->putBase($id, $service);
            }

            foreach ($validatedBootstrapServices as $id => $service) {
                if ($this->cache->tryGetBase($id, $existing)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Bootstrap service "%s" is already initialized.',
                        $id,
                    ));
                }

                $this->cache->putBase($id, $service);
            }
        }

        $this->delegators = $delegators ?? new DelegatorRegistry($this->callableExecutor);
        $this->externalContainers = $externalContainers;
        $this->cycleGuard = $cycleGuard ?? new CycleGuard();
        $this->proxyFactory = $proxyFactory ?? new ProxyFactory();
    }

    /** Creates a container from a {@see Config} instance. */
    public static function create(Config $config): Container
    {
        return ContainerBuilder::configure($config)->build();
    }

    /**
     * Retrieves an entry by identifier.
     *
     * Resolution order:
     * 1. External PSR-11 containers receive the original requested id.
     * 2. Decorated local cache by requested id.
     * 3. Local alias resolution to the canonical id.
     * 4. Local base cache by canonical id.
     * 5. Local resolver chain.
     * 6. Local delegators and decorated cache.
     *
     * Local aliases are never forwarded to external containers. External
     * entries are returned directly; the external container owns their
     * caching and lifecycle.
     *
     * @throws NotFoundException           If no resolver can handle the entry.
     * @throws CircularDependencyException If a cycle is detected.
     * @throws ResolutionException         If a resolver fails hard.
     */
    public function get(string $id): mixed
    {
        $externalGuard = "\0external:" . $id;
        $this->cycleGuard->enter($externalGuard);

        try {
            $external = $this->externalContainers?->findOwning($id);
            if ($external !== null) {
                return $external->get($id);
            }
        } finally {
            $this->cycleGuard->leave($externalGuard);
        }

        if ($this->cache->tryGetResolved($id, $entry)) {
            return $entry;
        }

        $entryId = $this->aliases->resolve($id);
        $this->cycleGuard->enter($entryId);

        try {
            return $this->resolveAndStore($id, $entryId);
        } finally {
            $this->cycleGuard->leave($entryId);
        }
    }

    /** Core local resolution step run inside the cycle guard. */
    private function resolveAndStore(string $requestedId, string $entryId): mixed
    {
        if (!$this->cache->tryGetBase($entryId, $entry)) {
            $entry = $this->resolver->resolve($entryId);
            $this->cache->putBase($entryId, $entry);
        }

        $entry = $this->delegators->apply($requestedId, $entry, $this);
        $this->cache->putResolved($requestedId, $entryId, $entry);

        return $entry;
    }

    public function has(string $id): bool
    {
        try {
            $guardId = "\0has:" . $id;
            $this->cycleGuard->enter($guardId);

            try {
                if ($this->externalContainers?->findOwning($id) !== null) {
                    return true;
                }

                if ($this->cache->tryGetResolved($id, $resolved)) {
                    return true;
                }

                $entryId = $this->aliases->resolve($id);

                if ($this->cache->tryGetBase($entryId, $base)) {
                    return true;
                }

                return $this->resolver->can($entryId);
            } finally {
                $this->cycleGuard->leave($guardId);
            }
        } catch (ContainerExceptionInterface) {
            return false;
        }
    }

    public function set(string $id, mixed $entry): void
    {
        self::assertMutableId($id, 'entry');
        $canonical = $this->aliases->resolve($id);

        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot replace protected DI id "%s".',
                $id,
            ));
        }

        $affectedDependencies = $this->deferredDependenciesResolvingTo($canonical);

        if ($entry instanceof DefinitionInterface) {
            if (!$this->resolver instanceof DefinitionAwareResolverInterface
                || !$this->resolver->supportsDefinition($entry)
            ) {
                throw InvalidConfigurationException::forInvalidDefinition($entry);
            }

            $this->resolver->setDefinition($canonical, $entry);
            $this->cache->removeBase($canonical);
        } else {
            if ($this->resolver instanceof DefinitionAwareResolverInterface) {
                $this->resolver->removeDefinition($canonical);
            }

            $this->cache->putBase($canonical, $entry);
        }

        $this->invalidate($id);
        $this->invalidateDeferredDelegators($affectedDependencies);
    }

    public function make(string $entry, array $params = []): object
    {
        $resolved = $this->aliases->resolve($entry);
        $this->cycleGuard->enter($resolved);

        try {
            try {
                $instance = $this->resolver->resolve($resolved, $params);
            } catch (ContainerExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw ResolutionException::forService($entry, $e);
            }

            if (!is_object($instance)) {
                throw ResolutionException::forNonObject($resolved, get_debug_type($instance));
            }

            return $instance;
        } finally {
            $this->cycleGuard->leave($resolved);
        }
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->callableExecutor->call($callable, $params);
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
            throw new InvalidConfigurationException(
                'The container cannot delegate lookups to itself.',
            );
        }

        if ($this->externalContainers?->contains($container) === true) {
            return;
        }

        $affectedDependencies = $this->deferredDependenciesTakenOverBy($container);
        ($this->externalContainers ??= new ExternalContainerRegistry())->register($container);
        $this->invalidateDeferredDelegators($affectedDependencies);
    }

    public function alias(string $alias, string $target): void
    {
        if (ProtectedServiceIds::contains($alias)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot replace protected DI alias "%s".',
                $alias,
            ));
        }

        $before = $this->deferredDependencyTargets();
        $previousCanonical = $this->aliases->resolve($alias);
        $this->aliases->set($alias, $target);
        $this->invalidate($alias, $previousCanonical);

        $changedDependencies = [];
        foreach ($before as $dependencyId => $previousTarget) {
            try {
                $currentTarget = $this->aliases->resolve($dependencyId);
            } catch (Throwable) {
                $currentTarget = null;
            }

            if ($currentTarget !== $previousTarget) {
                $changedDependencies[] = $dependencyId;
            }
        }

        $this->invalidateDeferredDelegators($changedDependencies);
    }

    /** @param callable|string|array{0: object|string, 1: string} $delegator */
    public function delegator(string $id, callable|string|array $delegator): void
    {
        self::assertMutableId($id, 'delegator');
        $canonical = $this->aliases->resolve($id);
        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot decorate protected DI id "%s".',
                $id,
            ));
        }

        $this->delegators->register($id, $delegator);
        $this->invalidate($id);
        $this->invalidateDeferredDelegators([$id]);
    }

    private static function assertMutableId(string $id, string $kind): void
    {
        if ($id === '') {
            throw new InvalidConfigurationException(sprintf(
                'Cannot register %s with an empty DI id.',
                $kind,
            ));
        }
    }

    private function invalidate(string $id, ?string $knownCanonical = null): void
    {
        try {
            $canonical = $knownCanonical ?? $this->aliases->resolve($id);
        } catch (Throwable) {
            $canonical = $id;
        }

        $this->cache->invalidate($id, $canonical);
        $this->delegators->invalidate($id);

        if ($canonical !== $id) {
            $this->delegators->invalidate($canonical);
        }
    }

    /** @return list<string> */
    private function deferredDependenciesResolvingTo(string $canonical): array
    {
        $dependencies = [];

        foreach ($this->delegators->deferredDependencies() as $dependencyId) {
            try {
                if ($this->aliases->resolve($dependencyId) === $canonical) {
                    $dependencies[] = $dependencyId;
                }
            } catch (Throwable) {
            }
        }

        return $dependencies;
    }

    /** @return list<string> */
    private function deferredDependenciesTakenOverBy(ContainerInterface $container): array
    {
        $dependencies = [];

        foreach ($this->delegators->deferredDependencies() as $dependencyId) {
            if ($this->externalContainers?->findOwning($dependencyId) !== null
                || !$container->has($dependencyId)
            ) {
                continue;
            }

            $dependencies[] = $dependencyId;
        }

        return $dependencies;
    }

    /** @return array<string, string|null> */
    private function deferredDependencyTargets(): array
    {
        $targets = [];

        foreach ($this->delegators->deferredDependencies() as $dependencyId) {
            try {
                $targets[$dependencyId] = $this->aliases->resolve($dependencyId);
            } catch (Throwable) {
                $targets[$dependencyId] = null;
            }
        }

        return $targets;
    }

    /** @param iterable<string> $dependencyIds */
    private function invalidateDeferredDelegators(iterable $dependencyIds): void
    {
        foreach ($this->delegators->invalidateDependencies($dependencyIds) as $entryId) {
            try {
                $canonical = $this->aliases->resolve($entryId);
            } catch (Throwable) {
                $canonical = $entryId;
            }

            $this->cache->invalidate($entryId, $canonical);
            $this->delegators->invalidate($entryId);

            if ($canonical !== $entryId) {
                $this->delegators->invalidate($canonical);
            }
        }
    }
}
