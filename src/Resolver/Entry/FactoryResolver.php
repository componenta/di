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
class FactoryResolver implements DefinitionAwareResolverInterface
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
    protected function resolveFactory(string $id): callable
    {
        $factory = $this->factories[$id];

        if ($factory instanceof FactoryDefinition) {
            $factory = $factory->value;
        }

        if ($factory instanceof ClassDefinition) {
            return $this->createFactoryFromDefinition($factory);
        }

        if (is_string($factory)) {
            $factory = $this->container->get($factory);
        } elseif (is_array($factory)
            && isset($factory[0], $factory[1])
            && is_string($factory[0])
        ) {
            $factory = [$this->container->get($factory[0]), $factory[1]];
        }

        if (!is_callable($factory)) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" is not callable.',
                $id,
            ));
        }

        return $factory;
    }

    /**
     * @param CompiledFactoryDefinition $definition
     * @return callable(array<string|int, mixed>): mixed
     */
    private function compiledFactory(CompiledFactoryDefinition $definition): callable
    {
        $file = $definition->file;
        if (!self::isAbsolutePath($file) && $this->compiledFactoryBaseDir !== null) {
            $file = rtrim($this->compiledFactoryBaseDir, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . ltrim($file, DIRECTORY_SEPARATOR);
        }

        if (!isset($this->compiledShards[$file])) {
            if (!is_file($file)) {
                throw new InvalidConfigurationException(sprintf(
                    'Compiled factory shard "%s" does not exist.',
                    $file,
                ));
            }

            $loaded = require $file;
            if (!is_object($loaded)) {
                throw new InvalidConfigurationException(sprintf(
                    'Compiled factory shard "%s" must return an object.',
                    $file,
                ));
            }

            $this->compiledShards[$file] = $loaded;
        }

        $shard = $this->compiledShards[$file];
        $method = $definition->method;

        if (!method_exists($shard, $method)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory method "%s::%s" does not exist.',
                $definition->class,
                $method,
            ));
        }

        return function (array $context) use ($shard, $method): mixed {
            try {
                return $shard->$method($this->container, $context);
            } catch (ContainerExceptionInterface $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw ResolutionException::forCallable([$shard, $method], $e);
            }
        };
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/'
                || $path[0] === '\\'
                || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'));
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

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition;
    }
}
