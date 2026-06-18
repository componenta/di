<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * Resolves container entries using factory callables or class definitions.
 *
 * Lazy strategy is opted-in by the factory itself: a factory class
 * implementing {@see LazyServiceFactoryInterface} signals "I can produce
 * my service in lazy form" and the resolver delegates to its `lazy()`
 * method. All other factories execute eagerly.
 *
 * Class-level {@see \Componenta\DI\Attribute\Lazy} / {@see \Componenta\DI\Attribute\Proxy}
 * attributes are honoured by {@see ReflectionResolver} for autowired
 * services. They are intentionally NOT consulted here - a factory is the
 * service's owner, and lazy semantics belong to it, not to the produced
 * class. Mixing both layers would impose a per-resolve reflection cost
 * with no consistency win.
 */
class FactoryResolver implements DefinitionAwareResolverInterface
{
    /**
     * @param array<string, callable|string|array|FactoryDefinition|ClassDefinition> $factories
     */
    public function __construct(
        protected array $factories,
        protected readonly ContainerInterface $container,
        protected readonly ProxyFactoryInterface $proxyFactory,
    ) {}

    public function can(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * Resolves an entry by executing its factory.
     *
     * @throws ResolutionException If factory execution fails.
     */
    public function resolve(string $id, array $context = []): mixed
    {
        try {
            $factory = $this->resolveFactory($id);
            $container = new ContainerValue($this->container);

            return $factory instanceof LazyServiceFactoryInterface
                ? $factory->lazy($container, $this->proxyFactory)
                : $factory($container);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    /**
     * Unwraps definition wrappers and resolves string/array factory references.
     */
    private function resolveFactory(string $id): mixed
    {
        $factory = $this->factories[$id];

        if ($factory instanceof FactoryDefinition) {
            $factory = $factory->value;
        } elseif ($factory instanceof ClassDefinition) {
            $factory = $this->createFactoryFromDefinition($factory);
        }

        if (!is_callable($factory)) {
            if (is_string($factory)) {
                $factory = $this->container->get($factory);
            } elseif (is_array($factory) && isset($factory[0]) && is_string($factory[0])) {
                $factory[0] = $this->container->get($factory[0]);
            }
        }

        return $factory;
    }

    protected function createFactoryFromDefinition(ClassDefinition $definition): callable
    {
        return function (ContainerValue $container) use ($definition) {
            $className = $definition->value;

            $resolveValue = static function (mixed $value) use ($container) {
                if ($value instanceof ReferenceDefinition) {
                    return $container->get($value->value);
                }
                return $value;
            };

            // Preserve keys so associative maps unpack as named arguments and
            // list-form maps unpack positionally - PHP handles both via `...`.
            $params = array_map($resolveValue, $definition->constructorParams);
            $instance = $params === []
                ? new $className()
                : new $className(...$params);

            foreach ($definition->methodCalls as $method => $methodParams) {
                $resolvedParams = array_map($resolveValue, $methodParams);
                $instance->$method(...$resolvedParams);
            }

            return $instance;
        };
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if (!$this->supportsDefinition($definition)) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        $this->factories[$id] = $definition;
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition;
    }
}
