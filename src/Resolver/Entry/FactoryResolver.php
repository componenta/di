<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryPathResolver;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ExplicitParametersResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/** Resolves container entries using factory callables or class definitions. */
class FactoryResolver implements DefinitionAwareResolverInterface
{
    /** @var array<string, object> */
    private array $compiledShards = [];

    /** @var array<string, callable(array<string|int, mixed>): mixed> */
    private array $compiledFactories = [];

    private readonly ExplicitParametersResolver $explicitParametersResolver;

    /** @param array<string, mixed> $factories */
    public function __construct(
        protected array $factories,
        protected readonly ContainerInterface $container,
        protected readonly ProxyFactoryInterface $proxyFactory,
        protected readonly ?ParametersResolver $parametersResolver = null,
        protected readonly ?AttributeProcessor $attributeProcessor = null,
        protected readonly ?string $compiledFactoryBaseDir = null,
    ) {
        $this->explicitParametersResolver = new ExplicitParametersResolver();

        foreach ($this->factories as $id => $factory) {
            if ($id === '') {
                throw new InvalidConfigurationException(
                    'Factory ids must be non-empty strings.',
                );
            }

            FactorySpecificationValidator::assertValid($id, $factory);

            if ($factory instanceof FactoryDefinition) {
                $this->factories[$id] = $factory->value;
            }
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
        if (!$this->can($id)) {
            throw NotFoundException::forService($id);
        }

        try {
            if (isset($this->compiledFactories[$id])) {
                return ($this->compiledFactories[$id])($context);
            }

            $definition = $this->factories[$id];
            $compiled = CompiledFactoryDefinition::decode($definition);
            if ($compiled !== null) {
                $factory = $this->compiledFactory(
                    $compiled,
                    $definition instanceof CompiledFactoryDefinition,
                );
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

        if ($factory instanceof ClassDefinition) {
            $factory = $this->createFactoryFromDefinition($factory);
        }

        if (is_string($factory) && $this->container->has($factory)) {
            $factory = $this->container->get($factory);
        } elseif (is_array($factory)
            && !is_callable($factory)
            && isset($factory[0])
            && is_string($factory[0])
            && $this->container->has($factory[0])
        ) {
            $factory[0] = $this->container->get($factory[0]);
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
    private function compiledFactory(
        CompiledFactoryDefinition $definition,
        bool $explicitDefinition = false,
    ): callable {
        if ($this->parametersResolver === null || $this->attributeProcessor === null) {
            throw new InvalidConfigurationException(
                'Compiled factories require the runtime parameter and attribute pipelines.',
            );
        }

        $file = (new CompiledFactoryPathResolver(
            $this->compiledFactoryBaseDir,
            $explicitDefinition,
        ))->resolve($definition->file);

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
                $loadedClass = require $file;
                if (!is_string($loadedClass)
                    || $loadedClass !== $class
                    || !class_exists($class, false)
                ) {
                    throw new InvalidConfigurationException(sprintf(
                        'Compiled factory shard "%s" returned an unexpected class.',
                        $file,
                    ));
                }
            } else {
                self::assertLoadedFromEquivalentShard($class, $file);
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

    /** @param class-string $class */
    private static function assertLoadedFromEquivalentShard(
        string $class,
        string $expectedFile,
    ): void {
        $loadedFile = (new ReflectionClass($class))->getFileName();
        $loadedFile = $loadedFile === false ? false : realpath($loadedFile);
        $expectedFile = realpath($expectedFile);

        if ($loadedFile === false || $expectedFile === false) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory class "%s" is already loaded from an unexpected file.',
                $class,
            ));
        }

        if ($loadedFile === $expectedFile) {
            return;
        }

        $loadedHash = hash_file('sha256', $loadedFile);
        $expectedHash = hash_file('sha256', $expectedFile);

        if (!is_string($loadedHash)
            || !is_string($expectedHash)
            || !hash_equals($loadedHash, $expectedHash)
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory class "%s" is already loaded from a different shard.',
                $class,
            ));
        }
    }

    /** @return callable(ContainerValue, array<string|int, mixed>): object */
    protected function createFactoryFromDefinition(ClassDefinition $definition): callable
    {
        return function (ContainerValue $container, array $context = []) use ($definition): object {
            $className = $definition->value;
            $configuredParams = $this->resolveDefinitionValue(
                $container,
                $definition->constructorParams,
            );

            if (!is_array($configuredParams)) {
                throw new InvalidConfigurationException('Resolved class constructor parameters must be an array.');
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                $instance = $reflection->newInstance();
            } else {
                $params = $this->explicitParametersResolver->resolveWithOverrides(
                    $constructor->getParameters(),
                    $configuredParams,
                    $context,
                );
                $instance = $reflection->newInstanceArgs($params);
            }

            foreach ($definition->methodCalls as $call) {
                $resolvedParams = $this->resolveDefinitionValue($container, $call['params']);
                if (!is_array($resolvedParams)) {
                    throw new InvalidConfigurationException('Resolved class method parameters must be an array.');
                }

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
        $this->factories[$id] = $definition instanceof FactoryDefinition
            ? $definition->value
            : $definition;
        unset($this->compiledFactories[$id]);
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition
            || $definition instanceof CompiledFactoryDefinition;
    }
}
