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

    /**
     * Collaborators are wired via the constructor - no post-injection.
     *
     * The internal state holders are optional in the signature so tests and
     * bespoke bootstrap code can plug in replacements; the builder always
     * passes fresh instances.
     */
    public function __construct(
        private EntryResolverInterface    $resolver,
        private AliasResolverInterface    $aliases,
        private CallableExecutorInterface $callableExecutor,
        ?EntryCache                       $cache = null,
        ?DelegatorRegistry                $delegators = null,
        ?ExternalContainerRegistry        $externalContainers = null,
        ?CycleGuard                       $cycleGuard = null,
        ?ProxyFactoryInterface            $proxyFactory = null,
    ) {
        $this->cache              = $cache ?? new EntryCache();
        $this->delegators         = $delegators ?? new DelegatorRegistry($this->callableExecutor);
        $this->externalContainers = $externalContainers ?? new ExternalContainerRegistry();
        $this->cycleGuard         = $cycleGuard ?? new CycleGuard();
        $this->proxyFactory       = $proxyFactory ?? new ProxyFactory();

        // Self-registration - the container advertises itself under every
        // interface it implements so resolvers can depend on it by type.
        $this->cache->putBase(ContainerInterface::class, $this);
        $this->cache->putBase(FactoryInterface::class, $this);
        $this->cache->putBase(CallableInvokerInterface::class, $this);
        $this->cache->putBase(ProxyFactoryInterface::class, $this);
        $this->cache->putBase(LazyObjectFactoryInterface::class, $this);
        $this->cache->putBase(VirtualProxyFactoryInterface::class, $this);
        $this->cache->putBase(self::class, $this);
        $this->cache->putBase(AliasResolverInterface::class, $this->aliases);
        $this->cache->putBase(CallableExecutorInterface::class, $this->callableExecutor);
    }

    /**
     * Creates a container from a {@see Config} instance.
     */
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
     * 3. External PSR-11 containers (inside the cycle guard).
     * 4. Resolver chain.
     * 5. Delegators applied on top of the produced value.
     *
     * @throws NotFoundException           If no resolver can handle the entry.
     * @throws CircularDependencyException If a cycle is detected.
     * @throws ResolutionException         If a resolver fails hard.
     */
    public function get(string $id): mixed
    {
        if ($this->cache->hasResolved($id)) {
            return $this->cache->getResolved($id);
        }

        $entryId = $this->aliases->resolve($id);

        return $this->cycleGuard->track(
            $entryId,
            fn(): mixed => $this->resolveAndStore($id, $entryId),
        );
    }

    /**
     * Core resolution step run inside the cycle guard.
     */
    private function resolveAndStore(string $requestedId, string $entryId): mixed
    {
        $external = $this->externalContainers->findOwning($entryId);

        $entry = $external !== null
            ? $external->get($entryId)
            : $this->resolveFromChain($entryId);

        $entry = $this->delegators->apply($requestedId, $entry, $this);

        $this->cache->putResolved($requestedId, $entryId, $entry);

        return $entry;
    }

    /**
     * Pulls the base entry from cache or falls through to the resolver chain.
     *
     * @throws NotFoundException
     */
    private function resolveFromChain(string $entryId): mixed
    {
        if ($this->cache->hasBase($entryId)) {
            return $this->cache->getBase($entryId);
        }

        $entry = $this->resolver->resolve($entryId);

        $this->cache->putBase($entryId, $entry);

        return $entry;
    }

    public function has(string $id): bool
    {
        if ($this->cache->hasResolved($id)) {
            return true;
        }

        // Only container-typed failures collapse to "absent"; real bugs
        // (e.g. TypeError in a resolver's can()) propagate.
        try {
            $entryId = $this->aliases->resolve($id);

            if ($this->externalContainers->has($entryId)) {
                return true;
            }

            if ($this->cache->hasBase($entryId)) {
                return true;
            }

            return $this->resolver->can($entryId);
        } catch (ContainerExceptionInterface) {
            return false;
        }
    }

    /**
     * Registers an entry or definition.
     *
     * Aliases are resolved before the base cache write so that a value set
     * under an alias name lands at the canonical id - otherwise
     * {@see resolveFromChain()} (which only consults the base cache by
     * canonical id) would miss it and recreate the entry from scratch.
     *
     * Definitions are forwarded to the resolver under the requested id since
     * the resolver-level table is alias-agnostic by design.
     *
     * @throws InvalidConfigurationException If the definition type is not
     *                                       supported by the resolver.
     */
    public function set(string $id, mixed $entry): void
    {
        if ($entry instanceof DefinitionInterface) {
            if (!$this->resolver instanceof DefinitionAwareResolverInterface
                || !$this->resolver->supportsDefinition($entry)
            ) {
                throw InvalidConfigurationException::forInvalidDefinition($entry);
            }

            $this->cache->removeBase($id);
            $this->resolver->setDefinition($id, $entry);
        } else {
            $canonical = $this->aliases->resolve($id);
            $this->cache->putBase($canonical, $entry);
        }

        $this->invalidate($id);
    }

    /**
     * Creates a new instance with dependency injection.
     *
     * Differences from {@see get()}:
     *  - Always returns a fresh instance - never consults or populates the
     *    cache, so repeat calls never share state.
     *  - Delegators registered via {@see delegator()} are intentionally
     *    **not** applied. `make()` is the "raw constructor" path: callers
     *    use it precisely to bypass the decoration that `get()` performs.
     *    Apply delegators explicitly at the call site if you need them.
     *  - External containers ({@see addContainer()}) are likewise skipped -
     *    they own caching/lifetime semantics that `make()` does not honour.
     *
     * Aliases are still resolved so callers can pass either an alias or the
     * canonical id.
     *
     * @throws ResolutionException If instantiation fails.
     */
    public function make(string $entry, array $params = []): object
    {
        $resolved = $this->aliases->resolve($entry);

        try {
            $instance = $this->resolver->resolve($resolved, $params);
        } catch (ContainerExceptionInterface $e) {
            // PSR-11 / DI exceptions preserve their concrete type so callers
            // can still differentiate them via the specific interface.
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($entry, $e);
        }

        if (!is_object($instance)) {
            throw ResolutionException::forNonObject($resolved, get_debug_type($instance));
        }

        return $instance;
    }

    /**
     * Invokes a callable with dependency injection.
     *
     * Exceptions thrown by the callable itself propagate unchanged.
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->callableExecutor->call($callable, $params);
    }

    /**
     * @inheritDoc
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        return $this->proxyFactory->makeLazy($class, $initializer);
    }

    /**
     * @inheritDoc
     */
    public function makeProxy(string $class, callable $factory): object
    {
        return $this->proxyFactory->makeProxy($class, $factory);
    }

    /**
     * Registers an external PSR-11 container as a delegated lookup source.
     *
     * External containers are probed before the resolver chain, in the order
     * they were registered.
     */
    public function addContainer(ContainerInterface $container): void
    {
        $this->externalContainers->register($container);
    }

    public function alias(string $alias, string $target): void
    {
        $this->aliases->set($alias, $target);
        $this->invalidate($alias);
    }

    /**
     * Registers a delegator (decorator) for an entry.
     *
     * Multiple delegators are applied in registration order. Non-closure forms
     * (service id, global function, `"Class::method"`, `[class-string|object, method]`)
     * are resolved through the callable resolver on first use.
     *
     * @throws DelegatorException If the delegator itself throws at invocation time.
     */
    public function delegator(string $id, callable|string|array $delegator): void
    {
        $this->delegators->register($id, $delegator);
        $this->invalidate($id);
    }

    /**
     * Invalidates every cached entry that could have been seeded under the
     * given id - directly, through an alias pointing at it, or through its
     * canonical target.
     */
    private function invalidate(string $id): void
    {
        // Best-effort resolve to canonical; a malformed alias map must not
        // abort cleanup here.
        try {
            $canonical = $this->aliases->resolve($id);
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
