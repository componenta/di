<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
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
 * Factory callables receive the container value and the resolution context.
 * Lazy factories receive the same context as the third lazy() argument.
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
    /** @var array<string, object> */
    private array $compiledShards = [];

    /** @var array<string, callable(array<string|int, mixed>): mixed> */
    private array $compiledFactories = [];

    /**
     * @param array<string, callable(ContainerValue, array<string|int, mixed>):mixed|string|array|FactoryDefinition|ClassDefinition|CompiledFactoryDefinition> $factories
     */
    public function __construct(
        protected array $factories,
        protected readonly ContainerInterface $container,
        protected readonly ProxyFactoryInterface $proxyFactory,
        protected readonly ?ParametersResolver $parametersResolver = null,
        protected readonly ?AttributeProcessor $attributeProcessor = null,
        protected readonly ?string $compiledFactoryBaseDir = null,
        protected readonly bool $trustedCompiledFactories = false,
    ) {
        foreach ($factories as $id => $factory) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException(
                    'Factory ids must be non-empty strings.',
                );
            }

            FactorySpecificationValidator::assertValid($id, $factory);
        }
    }

    public function can(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * Resolves an entry by executing its factory.
     *
     * @throws ResolutionException|ContainerExceptionInterface If factory execution fails.
     */
    public function resolve(string $id, array $context = []): mixed
    {
        try {
            if (isset($this->compiledFactories[$id])) {
                return ($this->compiledFactories[$id])($context);
            }

            $definition = $this->factories[$id];
            $compiled = CompiledFactoryDefinition::decode($definition);
            if ($compiled !== null) {
                $factory = $this->compiledFactory($compiled);
                $this->factories[$id] = $compiled;
                $this->compiledFactories[$id] = $factory;

                return $factory($context);
            }

            $factory = $this->resolveFactory($id);
            $container = new ContainerValue($this->container);

            return $factory instanceof LazyServiceFactoryInterface
                ? $factory->lazy($container, $this->proxyFactory, $context)
                : $factory($container, $context);
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

    private function compiledFactory(CompiledFactoryDefinition $definition): callable
    {
        if ($this->parametersResolver === null || $this->attributeProcessor === null) {
            throw new InvalidConfigurationException(
                'Compiled factories require the runtime parameter and attribute pipelines.',
            );
        }

        $file = $definition->file;
        if ($this->compiledFactoryBaseDir !== null && !self::isAbsolutePath($file)) {
            $file = rtrim($this->compiledFactoryBaseDir, '/\\') . '/' . ltrim($file, '/\\');
        }

        $shard = $this->compiledShards[$file] ?? null;

        if ($shard === null) {
            $class = $definition->class;

            // The loader, not every generated file, owns idempotence. This also
            // permits multiple container instances in one long-running worker.
            if (!class_exists($class, false)) {
                if (!$this->trustedCompiledFactories && !is_file($file)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Compiled factory shard "%s" does not exist.',
                        $file,
                    ));
                }

                $loadedClass = require $file;
                if (!$this->trustedCompiledFactories
                    && (!is_string($loadedClass)
                        || $loadedClass !== $class
                        || !class_exists($class, false))
                ) {
                    throw new InvalidConfigurationException(sprintf(
                        'Compiled factory shard "%s" returned an unexpected class.',
                        $file,
                    ));
                }
            }

            $shard = new $class(
                $this->parametersResolver->resolverList,
                $this->attributeProcessor->registry->handlers,
                $this->proxyFactory,
            );
            $this->compiledShards[$file] = $shard;
        }

        $factory = [$shard, $definition->method];
        if (!is_callable($factory)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory method "%s::%s" is not callable.',
                $definition->class,
                $definition->method,
            ));
        }

        return $factory;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/'
                || $path[0] === '\\'
                || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'));
    }

    protected function createFactoryFromDefinition(ClassDefinition $definition): callable
    {
        return function (ContainerValue $container, array $_context = []) use ($definition) {
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
        unset($this->compiledFactories[$id]);
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition
            || $definition instanceof CompiledFactoryDefinition;
    }
}
