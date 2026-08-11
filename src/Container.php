<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\DefinitionAwareResolverInterface;
use Componenta\DI\Resolver\Entry\DefinitionRemovalInterface;
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
 *  - {@see AliasResolverInterface}        - alias -> canonical id resolution.
 *  - {@see CallableExecutorInterface}     - resolve-and-invoke pipeline.
 *  - {@see ProxyFactoryInterface}         - produces lazy objects / virtual proxies.
 *  - {@see EntryCache}                    - two-tier base/decorated cache.
 *  - {@see DelegatorRegistry}             - decorator chain per entry.
 *  - {@see ExternalContainerRegistry}     - delegated PSR-11 containers.
 *  - {@see CycleGuard}                    - circular-dependency detection.
 */
final readonly class Container implements
    ContainerInterface,
    FactoryInterface,
    CallableInvokerInterface,
    ProxyFactoryInterface
{
    private EntryCache $cache;

    private DelegatorRegistry $delegators;

    private ExternalContainerRegistry $externalContainers;

    private CycleGuard $cycleGuard;

    private ProxyFactoryInterface $proxyFactory;

    private RuntimeDefinitionRegistry $runtimeDefinitions;

    /**
     * Collaborators are wired via the constructor - no post-injection.
     *
     * The internal state holders are optional in the signature so tests and
     * bespoke bootstrap code can plug in replacements; the builder always
     * passes fresh instances.
     *
     * @param array<array-key, mixed> $bootstrapServices
     */
    public function __construct(
        private EntryResolverInterface    $resolver,
        private AliasResolverInterface    $aliases,
        private CallableExecutorInterface $callableExecutor,
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
            AliasResolverInterface::class => $this->aliases,
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

        $this->delegators         = $delegators ?? new DelegatorRegistry($this->callableExecutor);
        $this->externalContainers = $externalContainers ?? new ExternalContainerRegistry();
        $this->cycleGuard         = $cycleGuard ?? new CycleGuard();
        $this->proxyFactory       = $proxyFactory ?? new ProxyFactory();
        $this->runtimeDefinitions = new RuntimeDefinitionRegistry();
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
     * 1. Decorated cache (by requested id).
     * 2. Alias resolution to canonical id.
     * 3. Local base cache by canonical id.
     * 4. External PSR-11 containers (inside the cycle guard).
     * 5. Resolver chain.
     * 6. Delegators applied on top of the produced value.
     *
     * @throws NotFoundException           If no resolver can handle the entry.
     * @throws CircularDependencyException If a cycle is detected.
     * @throws ResolutionException         If a resolver fails hard.
     */
    public function get(string $id): mixed
    {
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

    /** Core resolution step run inside the cycle guard. */
    private function resolveAndStore(string $requestedId, string $entryId): mixed
    {
        if (!$this->cache->tryGetBase($entryId, $entry)) {
            $external = $this->externalContainers->findOwning($entryId);

            if ($external !== null) {
                $entry = $external->get($entryId);
            } else {
                $entry = $this->resolver->resolve($entryId);
                $this->cache->putBase($entryId, $entry);
            }
        }

        $entry = $this->delegators->apply($requestedId, $entry, $this);
        $this->cache->putResolved($requestedId, $entryId, $entry);

        return $entry;
    }

    public function has(string $id): bool
    {
        if ($this->cache->tryGetResolved($id, $resolved)) {
            return true;
        }

        // Only container-typed failures collapse to "absent"; real bugs
        // (e.g. TypeError in a resolver's can()) propagate. A distinct guard
        // key prevents mutually delegated containers from recursively probing
        // each other's has() forever without interfering with service-cycle
        // diagnostics used by get().
        try {
            $entryId = $this->aliases->resolve($id);
            $guardId = "\0has:" . $entryId;
            $this->cycleGuard->enter($guardId);

            try {
                if ($this->cache->tryGetBase($entryId, $base)) {
                    return true;
                }

                if ($this->externalContainers->findOwning($entryId) !== null) {
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

    /**
     * Registers an entry or definition.
     *
     * Aliases are resolved before the base cache write so that a value set
     * under an alias name lands at the canonical id. Definitions use the same
     * canonical id as ordinary values.
     *
     * @throws InvalidConfigurationException If the definition type is not supported.
     */
    public function set(string $id, mixed $entry): void
    {
        $canonical = $this->aliases->resolve($id);

        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot replace protected DI id "%s".',
                $id,
            ));
        }

        if ($entry instanceof DefinitionInterface) {
            if (!$this->resolver instanceof DefinitionAwareResolverInterface
                || !$this->resolver->supportsDefinition($entry)
            ) {
                throw InvalidConfigurationException::forInvalidDefinition($entry);
            }

            $this->resolver->setDefinition($canonical, $entry);
            $this->cache->removeBase($canonical);
            $this->runtimeDefinitions->mark($canonical);
        } else {
            if ($this->runtimeDefinitions->has($canonical)) {
                if (!$this->resolver instanceof DefinitionRemovalInterface) {
                    throw new InvalidConfigurationException(sprintf(
                        'Resolver "%s" cannot remove runtime definition "%s" before storing a value.',
                        $this->resolver::class,
                        $canonical,
                    ));
                }

                $this->resolver->removeDefinition($canonical);
                $this->runtimeDefinitions->clear($canonical);
            }

            $this->cache->putBase($canonical, $entry);
        }

        $this->invalidate($id);
    }

    /**
     * Performs an uncached object resolution with dependency injection.
     *
     * The container does not consult or populate shared-entry caches, apply
     * delegators, or query external containers on this path. Object identity is
     * controlled by the selected resolver or user factory.
     *
     * Aliases are still resolved so callers can pass either an alias or the
     * canonical id.
     *
     * @param array<string|int, mixed> $params
     * @throws ResolutionException If instantiation fails.
     */
    public function make(string $entry, array $params = []): object
    {
        $resolved = $this->aliases->resolve($entry);

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
    }

    /** Invokes a callable with dependency injection. */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->callableExecutor->call($callable, $params);
    }

    /** @inheritDoc */
    public function makeLazy(string $class, callable $initializer): object
    {
        return $this->proxyFactory->makeLazy($class, $initializer);
    }

    /** @inheritDoc */
    public function makeProxy(string $class, callable $factory): object
    {
        return $this->proxyFactory->makeProxy($class, $factory);
    }

    /** Registers an external PSR-11 container as a delegated lookup source. */
    public function addContainer(ContainerInterface $container): void
    {
        if ($container === $this) {
            throw new InvalidConfigurationException(
                'The container cannot delegate lookups to itself.',
            );
        }

        $this->externalContainers->register($container);
    }

    public function alias(string $alias, string $target): void
    {
        if (ProtectedServiceIds::contains($alias)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot replace protected DI alias "%s".',
                $alias,
            ));
        }

        $previousCanonical = $this->aliases->resolve($alias);
        $this->aliases->set($alias, $target);
        $this->invalidate($alias, $previousCanonical);
    }

    /**
     * Registers a delegator (decorator) for an entry.
     *
     * Multiple delegators are applied in registration order. Non-closure forms
     * are resolved through the callable resolver on first use.
     *
     * @param callable|string|array{0: object|string, 1: string} $delegator
     * @throws DelegatorException If the delegator itself throws at invocation time.
     */
    public function delegator(string $id, callable|string|array $delegator): void
    {
        $canonical = $this->aliases->resolve($id);
        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot decorate protected DI id "%s".',
                $id,
            ));
        }

        $this->delegators->register($id, $delegator);
        $this->invalidate($id);
    }

    /**
     * Invalidates every cached entry that could have been seeded under the
     * given id - directly, through an alias pointing at it, or through its
     * canonical target.
     */
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
}
