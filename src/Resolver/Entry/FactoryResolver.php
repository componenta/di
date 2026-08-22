<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Compile\Factory\CompiledFactoryPathResolver;
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/** Resolves configured factories, class definitions and compiled entry shards. */
final class FactoryResolver implements DefinitionAwareResolverInterface
{
    /** @var array<string,object> */
    private array $compiledShards = [];
    /** @var array<string,callable(array<string|int,mixed>):mixed> */
    private array $compiledFactories = [];
    /** @var array<string,true> */
    private array $validateResolvedFactories = [];

    /** @param array<string,mixed> $factories */
    public function __construct(
        private array $factories,
        private readonly ContainerInterface $container,
        private readonly ProxyFactoryInterface $proxyFactory,
        private readonly ObjectPipeline $objects,
        private readonly CallableExecutorInterface $executor,
        private readonly ?string $compiledFactoryBaseDir = null,
    ) {
        foreach ($this->factories as $id => $factory) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Factory ids must be non-empty strings.');
            }

            if (is_string($factory) || !is_callable($factory)) {
                $this->validateResolvedFactories[$id] = true;
            }

            FactorySpecificationValidator::assertValid($id, $factory);
            if ($factory instanceof FactoryDefinition) {
                $this->factories[$id] = $factory->value;
                if (is_string($factory->value) || !is_callable($factory->value)) {
                    $this->validateResolvedFactories[$id] = true;
                }
            }
        }
    }

    public function can(string $id): bool
    {
        return array_key_exists($id, $this->factories);
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): mixed
    {
        if (!$this->can($id)) {
            throw NotFoundException::forService($id);
        }

        try {
            if (isset($this->compiledFactories[$id])) {
                return ($this->compiledFactories[$id])($params);
            }

            $definition = $this->factories[$id];
            $compiled = CompiledFactoryDefinition::decode($definition);
            if ($compiled !== null) {
                $factory = $this->compiledFactory(
                    $compiled,
                    $definition instanceof CompiledFactoryDefinition,
                );
                $this->compiledFactories[$id] = $factory;
                return $factory($params);
            }

            if ($definition instanceof ClassDefinition) {
                return $this->classDefinition($definition, $params);
            }

            $factory = $this->resolveFactory($id);
            $container = new ContainerValue($this->container);
            $factoryParams = MappedRequestContext::strip($params);

            return $factory instanceof LazyServiceFactoryInterface
                ? $factory->lazy($container, $this->proxyFactory, $factoryParams)
                : $factory($container, $factoryParams);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    /** @param array<string|int, mixed> $params */
    private function classDefinition(ClassDefinition $definition, array $params): object
    {
        $configured = $this->resolveDefinitionValue($definition->constructorParams);
        if (!is_array($configured)) {
            throw new InvalidConfigurationException('ClassDefinition constructor parameters must resolve to an array.');
        }

        /** @var array<string|int,mixed> $configured */
        $entry = $this->objects->create(
            $definition->value,
            $this->constructorParameters($definition, $configured, $params),
        );

        foreach ($definition->methodCalls as $call) {
            $methodParams = $this->resolveDefinitionValue($call['params']);
            if (!is_array($methodParams)) {
                throw new InvalidConfigurationException('ClassDefinition method parameters must resolve to an array.');
            }
            /** @var array<string|int,mixed> $methodParams */
            $this->executor->call([$entry, $call['method']], $methodParams);
        }

        return $entry;
    }

    /**
     * @param array<string|int,mixed> $configured
     * @param array<string|int,mixed> $runtime
     * @return array<string|int,mixed>
     */
    private function constructorParameters(
        ClassDefinition $definition,
        array $configured,
        array $runtime,
    ): array {
        if ($configured === []) {
            return $runtime;
        }
        if ($runtime === []) {
            return $configured;
        }

        $provided = array_replace($configured, $runtime);
        foreach ($this->objects->constructorTargets($definition->value) as $target) {
            if (array_key_exists($target->name, $runtime)) {
                $provided[$target->name] = $runtime[$target->name];
                continue;
            }

            if (array_key_exists($target->position, $runtime)) {
                $provided[$target->name] = $runtime[$target->position];
                continue;
            }

            foreach ($target->typeNames as $typeName) {
                if (!array_key_exists($typeName, $runtime)) {
                    continue;
                }

                $value = $runtime[$typeName];
                if (is_object($value)
                    && $value instanceof $typeName
                    && $target->accepts($value)
                ) {
                    $provided[$target->name] = $value;
                    break;
                }
            }
        }

        return $provided;
    }

    private function resolveDefinitionValue(mixed $value): mixed
    {
        if ($value instanceof ReferenceDefinition) {
            return $this->container->get($value->value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveDefinitionValue($item);
        }
        return $resolved;
    }

    private function resolveFactory(string $id): callable|LazyServiceFactoryInterface
    {
        $factory = $this->factories[$id];

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

        if (!$factory instanceof LazyServiceFactoryInterface && !is_callable($factory)) {
            throw new InvalidConfigurationException(sprintf(
                'Factory service for "%s" resolved to unsupported %s.',
                $id,
                get_debug_type($factory),
            ));
        }
        if (isset($this->validateResolvedFactories[$id])
            && !$factory instanceof LazyServiceFactoryInterface
        ) {
            FactorySpecificationValidator::assertResolvedCallable($id, $factory);
        }
        return $factory;
    }

    /** @return callable(array<string|int,mixed>):mixed */
    private function compiledFactory(
        CompiledFactoryDefinition $definition,
        bool $explicitDefinition,
    ): callable {
        $file = (new CompiledFactoryPathResolver(
            $this->compiledFactoryBaseDir,
            $explicitDefinition,
        ))->resolve($definition->file);
        if (!$explicitDefinition) {
            self::assertContentAddressedShard($file);
        }

        $class = $definition->class;
        $shard = $this->compiledShards[$file] ?? null;
        if ($shard === null) {
            if (!class_exists($class, false)) {
                $loaded = require $file;
                if (!is_string($loaded) || $loaded !== $class || !class_exists($class, false)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Compiled shard "%s" returned an unexpected class.',
                        $file,
                    ));
                }
            }

            if (defined($class . '::FAST_PATHS')) {
                throw new InvalidConfigurationException(sprintf(
                    'Compiled shard "%s" uses the obsolete semantic fast-path format; rebuild the DI cache.',
                    $class,
                ));
            }

            self::assertShardFormat($class);
            $shard = new $class($this->objects);
            $this->compiledShards[$file] = $shard;
        }

        $this->assertCompiledEntry($class, $definition);

        $factory = [$shard, $definition->method];
        if (!is_callable($factory)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory %s::%s is not callable.',
                $definition->class,
                $definition->method,
            ));
        }

        return $factory;
    }

    /** @param class-string $class */
    private static function assertShardFormat(string $class): void
    {
        $constant = $class . '::FORMAT_VERSION';
        $version = defined($constant) ? constant($constant) : null;
        if ($version !== CompiledFactoryShardCompiler::FORMAT_VERSION) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" uses unsupported format; rebuild the DI cache.',
                $class,
            ));
        }
    }

    /** @param class-string $class */
    private function assertCompiledEntry(string $class, CompiledFactoryDefinition $definition): void
    {
        $constant = $class . '::ENTRIES';
        $entries = defined($constant) ? constant($constant) : null;
        if (!is_array($entries)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" has no valid entry metadata; rebuild the DI cache.',
                $class,
            ));
        }

        $entry = $entries[$definition->method] ?? null;
        if (!is_string($entry) || $entry === '' || !class_exists($entry)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" is stale for method "%s"; rebuild the DI cache.',
                $class,
                $definition->method,
            ));
        }

        try {
            $creatable = $this->objects->canCreate($entry);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" cannot validate entry "%s"; rebuild the DI cache.',
                $class,
                $entry,
            ), previous: $e);
        }

        if (!$creatable) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" targets runtime-ineligible entry "%s"; rebuild the DI cache.',
                $class,
                $entry,
            ));
        }
    }

    private static function assertContentAddressedShard(string $file): void
    {
        if (preg_match('/^container\\.factories\\.([a-f0-9]{32})\\.php$/D', basename($file), $matches) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" is not content-addressed.',
                $file,
            ));
        }
        $hash = hash_file('sha256', $file);
        if (!is_string($hash) || !hash_equals($matches[1], substr($hash, 0, 32))) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled shard "%s" failed its content hash check.',
                $file,
            ));
        }
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
        unset($this->compiledFactories[$id], $this->validateResolvedFactories[$id]);
        if ($definition instanceof FactoryDefinition
            && (is_string($definition->value) || !is_callable($definition->value))
        ) {
            $this->validateResolvedFactories[$id] = true;
        }
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition
            || $definition instanceof CompiledFactoryDefinition;
    }
}
