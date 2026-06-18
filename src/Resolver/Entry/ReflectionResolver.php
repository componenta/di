<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\Reflection\Reflection;
use ReflectionClass;

/**
 * Resolves entries by reflection-driven autowiring.
 *
 * Orchestrates three collaborators behind interfaces - each owns a single
 * step of the construction pipeline:
 *
 *  - {@see InstantiatorInterface}     - constructor invocation / {@see \Componenta\DI\Attribute\NoConstructor}
 *  - {@see PropertyInjectorInterface} - attribute-driven property injection
 *  - {@see PostInitializerInterface}  - post-construction {@see \Componenta\DI\Attribute\SetUp} methods
 *
 * Construction mode is decided here directly. Classes without lazy markers
 * are built eagerly. The {@see Lazy} attribute opts into a PHP 8.4 lazy
 * object (ghost), which preserves class identity. The {@see Proxy}
 * attribute opts into a virtual proxy when forwarding semantics are needed.
 */
final class ReflectionResolver implements EntryResolverInterface
{
    /** @var array<class-string, Strategy> */
    private array $strategyCache = [];

    public function __construct(
        private readonly InstantiatorInterface $instanceCreator,
        private readonly PropertyInjectorInterface $propertyInjector,
        private readonly PostInitializerInterface $setUpRunner,
        private readonly ProxyFactoryInterface $proxyFactory,
    ) {}

    public function can(string $id): bool
    {
        return Reflection::class($id)?->isInstantiable() ?? false;
    }

    /**
     * @throws ResolutionException If the class cannot be reflected.
     */
    public function resolve(string $id, array $context = []): object
    {
        $reflector = Reflection::class($id);

        if ($reflector === null) {
            throw ResolutionException::forMissingService($id);
        }

        return match ($this->detectStrategy($reflector)) {
            Strategy::VirtualProxy => $this->buildVirtualProxy($reflector, $context),
            Strategy::Lazy         => $this->buildLazy($reflector, $context),
            Strategy::Eager        => $this->buildEager($reflector, $context),
        };
    }

    /**
     * Eager construction - invoke ctor, inject properties, run SetUp.
     *
     * Used both as the unwrapped path (called from inside lazy/proxy
     * initializers) and the only direct caller.
     */
    private function buildEager(ReflectionClass $reflector, array $context): object
    {
        $entry = $this->instanceCreator->create($reflector, $context);
        $this->propertyInjector->inject($reflector, $entry, $context);
        $this->setUpRunner->run($reflector, $entry, $context);

        return $entry;
    }

    /**
     * Lazy-object path: PHP allocates the shell up front; the constructor
     * runs on it in place at first observable access, then the rest of
     * the pipeline populates it.
     */
    private function buildLazy(ReflectionClass $reflector, array $context): object
    {
        return $this->proxyFactory->makeLazy(
            $reflector->getName(),
            function (object $entry) use ($reflector, $context): void {
                $this->instanceCreator->initialize($entry, $reflector, $context);
                $this->propertyInjector->inject($reflector, $entry, $context);
                $this->setUpRunner->run($reflector, $entry, $context);
            },
        );
    }

    /**
     * Virtual-proxy path: returns a forwarding subtype. The real instance
     * is built eagerly (by the same pipeline) on first access and
     * subsequent calls are routed to it.
     */
    private function buildVirtualProxy(ReflectionClass $reflector, array $context): object
    {
        return $this->proxyFactory->makeProxy(
            $reflector->getName(),
            fn(object $proxy): object => $this->buildEager($reflector, $context),
        );
    }

    /**
     * Determines the resolution strategy for a class.
     *
     * Priority:
     *  1. {@see Proxy} attribute on the class -> virtual proxy.
     *  2. {@see Lazy} attribute on the class -> lazy object (ghost).
     *  3. No attribute -> eager construction.
     *
     * Eager is the default because lazy wrappers carry per-resolve cost
     * (one `newLazyGhost` allocation per service) that outweighs deferred
     * construction for cheap services. Mark heavy services with {@see Lazy}
     * or {@see Proxy} explicitly.
     *
     * Result is memoised per class.
     */
    private function detectStrategy(ReflectionClass $reflector): Strategy
    {
        $key = $reflector->getName();

        if (isset($this->strategyCache[$key])) {
            return $this->strategyCache[$key];
        }

        if ($reflector->getAttributes(Proxy::class) !== []) {
            return $this->strategyCache[$key] = Strategy::VirtualProxy;
        }

        if ($reflector->getAttributes(Lazy::class) !== []) {
            return $this->strategyCache[$key] = Strategy::Lazy;
        }

        return $this->strategyCache[$key] = Strategy::Eager;
    }
}
