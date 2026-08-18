<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\DefinitionAwareResolverInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
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
    private readonly ProxyFactoryInterface $proxyFactory;

    /** @param array<array-key, mixed> $bootstrapServices */
    public function __construct(
        private readonly EntryResolverInterface $resolver,
        private readonly AliasResolver $aliases,
        private readonly CallableExecutorInterface $callableExecutor,
        ?EntryCache $cache = null,
        ?DelegatorRegistry $delegators = null,
        ?ExternalContainerRegistry $externalContainers = null,
        ?CycleGuard $cycleGuard = null,
        ?ProxyFactoryInterface $proxyFactory = null,
        array $bootstrapServices = [],
    ) {
        $core = [
            ContainerInterface::class => $this,
            FactoryInterface::class => $this,
            CallableInvokerInterface::class => new CallableInvoker(),
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

        $this->cache = $cache ?? new EntryCache();
        foreach ($core + $validated as $id => $service) {
            $this->cache->putBase($id, $service);
        }

        $this->delegators = $delegators ?? new DelegatorRegistry($this);
        $this->externalContainers = $externalContainers;
        $this->cycleGuard = $cycleGuard ?? new CycleGuard();
        $this->proxyFactory = $proxyFactory ?? new ProxyFactory();
    }

    public static function create(Config $config): self
    {
        return ContainerBuilder::configure($config)->build();
    }

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
        $this->cycleGuard->enterShared($entryId);
        try {
            return $this->resolveAndStore($id, $entryId);
        } finally {
            $this->cycleGuard->leaveShared($entryId);
        }
    }

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
            throw new InvalidConfigurationException(sprintf('Cannot replace protected DI id "%s".', $id));
        }

        $affected = $this->deferredDependenciesResolvingTo($canonical);
        if ($entry instanceof DefinitionInterface) {
            if (!$this->resolver instanceof DefinitionAwareResolverInterface
                || !$this->resolver->supportsDefinition($entry)
            ) {
                throw InvalidConfigurationException::forInvalidDefinition($entry);
            }
            $this->resolver->setDefinition($canonical, $entry);
            $this->cache->removeBase($canonical);
        } else {
            $this->cache->putBase($canonical, $entry);
        }

        $this->invalidate($id);
        $this->invalidateDeferredDelegators($affected);
    }

    public function make(
        string $entry,
        ResolutionContext $context = new ResolutionContext(),
    ): object {
        $resolved = $this->aliases->resolve($entry);
        $this->cycleGuard->enter($resolved);
        try {
            try {
                $instance = $this->resolver->resolve($resolved, $context);
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

    public function resolve(mixed $callable): callable
    {
        return $this->callableExecutor->resolve($callable);
    }

    public function execute(
        mixed $callable,
        ResolutionContext $context = new ResolutionContext(),
    ): mixed {
        return $this->callableExecutor->execute($callable, $context);
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
        if ($this->externalContainers?->contains($container) === true) {
            return;
        }

        $affected = $this->deferredDependenciesTakenOverBy($container);
        ($this->externalContainers ??= new ExternalContainerRegistry())->register($container);
        $this->invalidateDeferredDelegators($affected);
    }

    public function alias(string $alias, string $target): void
    {
        if (ProtectedServiceIds::contains($alias)) {
            throw new InvalidConfigurationException(sprintf('Cannot replace protected DI alias "%s".', $alias));
        }

        $before = $this->deferredDependencyTargets();
        $previous = $this->aliases->resolve($alias);
        $this->aliases->set($alias, $target);
        $this->invalidate($alias, $previous);

        $changed = [];
        foreach ($before as $dependencyId => $previousTarget) {
            try {
                $currentTarget = $this->aliases->resolve($dependencyId);
            } catch (Throwable) {
                $currentTarget = null;
            }
            if ($currentTarget !== $previousTarget) {
                $changed[] = $dependencyId;
            }
        }
        $this->invalidateDeferredDelegators($changed);
    }

    /** @param callable|string|array{0: object|string, 1: string} $delegator */
    public function delegator(string $id, callable|string|array $delegator): void
    {
        self::assertMutableId($id, 'delegator');
        $canonical = $this->aliases->resolve($id);
        if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
            throw new InvalidConfigurationException(sprintf('Cannot decorate protected DI id "%s".', $id));
        }

        $this->delegators->register($id, $delegator);
        $this->invalidate($id);
        $this->invalidateDeferredDelegators([$id]);
    }

    private static function assertMutableId(string $id, string $kind): void
    {
        if ($id === '') {
            throw new InvalidConfigurationException(sprintf('Cannot register %s with an empty DI id.', $kind));
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
            if ($this->externalContainers?->findOwning($dependencyId) !== null || !$container->has($dependencyId)) {
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
