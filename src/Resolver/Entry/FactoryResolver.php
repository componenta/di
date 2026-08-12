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

/** Resolves container entries using factory callables or class definitions. */
class FactoryResolver implements DefinitionAwareResolverInterface, DefinitionRemovalInterface
{
    /** @var array<string, object> */
    private array $compiledShards = [];

    /** @var array<string, callable(array<string|int, mixed>): mixed> */
    private array $compiledFactories = [];

    /** @param array<string, mixed> $factories */
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
            if ($id === '') {
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
     * @param array<string|int, mixed> $context
     * @throws ResolutionException|ContainerExceptionInterface
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

    /** @return callable(ContainerValue, array<string|int, mixed>): mixed */
    private function resolveFactory(string $id): callable
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

        if (!is_callable($factory)) {
            throw new InvalidConfigurationException(sprintf(
                'Factory service for "%s" resolved to non-callable %s.',
                $id,
                get_debug_type($factory),
            ));
        }

        return $factory;
    }

    /** @return callable(array<string|int, mixed>): mixed */
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

        $class = $definition->class;
        $shard = $this->compiledShards[$file] ?? null;

        if ($shard !== null && $shard::class !== $class) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory shard "%s" was already loaded as "%s", not "%s".',
                $file,
                $shard::class,
                $class,
            ));
        }

        if ($shard === null) {
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
                || (strlen($path) >= 3
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && ($path[2] === '/' || $path[2] === '\\')));
    }

    /** @return callable(ContainerValue, array<string|int, mixed>): object */
    protected function createFactoryFromDefinition(ClassDefinition $definition): callable
    {
        return function (ContainerValue $container, array $_context = []) use ($definition): object {
            $className = $definition->value;
            $resolveValue = fn(mixed $value): mixed => $this->resolveDefinitionValue(
                $container,
                $value,
            );

            $params = array_map($resolveValue, $definition->constructorParams);
            $instance = $params === []
                ? new $className()
                : new $className(...$params);

            foreach ($definition->methodCalls as $call) {
                $resolvedParams = array_map($resolveValue, $call['params']);
                $method = $call['method'];
                $instance->$method(...$resolvedParams);
            }

            return $instance;
        };
    }

    private function resolveDefinitionValue(
        ContainerValue $container,
        mixed $value,
    ): mixed {
        if ($value instanceof ReferenceDefinition) {
            return $container->get($value->value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];

        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveDefinitionValue($container, $item);
        }

        return $resolved;
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if (!$this->supportsDefinition($definition)) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        FactorySpecificationValidator::assertValid($id, $definition);
        $this->factories[$id] = $definition;
        unset($this->compiledFactories[$id]);
    }

    public function removeDefinition(string $id): void
    {
        unset($this->factories[$id], $this->compiledFactories[$id]);
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition
            || $definition instanceof CompiledFactoryDefinition;
    }
}
