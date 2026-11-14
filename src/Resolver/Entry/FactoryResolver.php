<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Entry\ConfiguredFactoryDefinition;
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Internal\Resolver\Entry\PreparedFactoryDefinition;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionReference;
use Throwable;

/** Validates and executes factory definitions without owning registrations. */
final class FactoryResolver implements DefinitionResolverInterface
{
    private readonly AutowireByTypeResolver $autowire;
    private readonly ParameterTargetFactory $targets;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ProxyFactoryInterface $proxyFactory,
    ) {
        $this->autowire = new AutowireByTypeResolver($container);
        $this->targets = new ParameterTargetFactory();

    }

    /** @param array<string|int, mixed> $params */
    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed
    {
        try {
            if ($validated instanceof ClassDefinition) {
                return $this->classDefinition($validated, $params);
            }
            if (!$validated instanceof PreparedFactoryDefinition) {
                throw InvalidConfigurationException::forUnsupportedDefinition($validated, self::class);
            }

            $factory = $this->resolveFactory($id, $validated);
            $container = $this->container->get(ContainerValue::class);
            if (!$container instanceof ContainerValue) {
                throw new InvalidConfigurationException('ContainerValue bootstrap service is unavailable.');
            }

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
        $class = $definition->value;
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $arguments = $this->arguments($constructor, $definition->constructorParams, MappedRequestContext::strip($params), $definition->autowire);
        $entry = new $class(...$arguments);

        foreach ($definition->methodCalls as $call) {
            $method = $reflection->hasMethod($call['method']) ? $reflection->getMethod($call['method']) : null;
            $methodParams = $method?->isPublic() === true
                ? $this->arguments($method, $call['params'], [], $definition->autowire)
                : $this->resolveDefinitionValue($call['params']);
            if (!is_array($methodParams)) {
                throw new InvalidConfigurationException('ClassDefinition method parameters must resolve to an array.');
            }
            $entry->{$call['method']}(...$methodParams);
        }

        return $entry;
    }

    /**
     * @param array<string|int,mixed> $configured
     * @param array<string|int,mixed> $runtime
     * @return array<string|int,mixed>
     */
    private function arguments(?ReflectionMethod $method, array $configured, array $runtime, bool $autowire): array
    {
        $arguments = [];
        $context = new ParameterResolutionContext(array_replace($configured, $runtime));
        $parameters = $method?->getParameters() ?? [];
        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                return $this->variadicArguments($parameters, $arguments, $configured, $runtime);
            }
            $name = $parameter->getName();
            $position = $parameter->getPosition();
            if (array_key_exists($name, $runtime)) {
                $arguments[$name] = $runtime[$name];
            } elseif (array_key_exists($position, $runtime)) {
                $arguments[$name] = $runtime[$position];
            } elseif (array_key_exists($name, $configured)) {
                $arguments[$name] = $this->resolveDefinitionValue($configured[$name]);
            } elseif (array_key_exists($position, $configured)) {
                $arguments[$name] = $this->resolveDefinitionValue($configured[$position]);
            } elseif ($autowire) {
                $resolved = $this->autowire->resolveParameter($this->targets->create($parameter), $context);
                if ($resolved !== null) {
                    $arguments[$name] = $resolved[1];
                }
            }
            unset($configured[$name], $configured[$position], $runtime[$name], $runtime[$position]);
        }
        if ($configured !== []) {
            throw new InvalidConfigurationException(sprintf(
                'Unknown ClassDefinition arguments for %s: %s.',
                $method === null ? 'a class without a constructor' : $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()',
                implode(', ', array_map(static fn(string|int $key): string => (string) $key, array_keys($configured))),
            ));
        }
        return $arguments;
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string,mixed> $arguments
     * @param array<string|int,mixed> $configured
     * @param array<string|int,mixed> $runtime
     * @return array<string|int,mixed>
     */
    private function variadicArguments(array $parameters, array $arguments, array $configured, array $runtime): array
    {
        $positional = [];
        $named = [];
        foreach (array_replace($configured, $runtime) as $key => $value) {
            $value = array_key_exists($key, $runtime) ? $value : $this->resolveDefinitionValue($value);
            if (is_int($key)) {
                $positional[] = $value;
            } else {
                $named[$key] = $value;
            }
        }
        if ($positional === []) {
            return $arguments + $named;
        }

        // Positional variadic values require the preceding arguments to occupy
        // their native positions. Evaluate omitted defaults only for this call.
        $fixed = [];
        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $name = $parameter->getName();
            if (array_key_exists($name, $arguments)) {
                $fixed[] = $arguments[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $fixed[] = $parameter->getDefaultValue();
            } else {
                throw ResolutionException::forParameter($parameter, reason: 'Required argument was not supplied.');
            }
        }
        return [...$fixed, ...$positional, ...$named];
    }

    /** @param array<string,true> $references */
    private function resolveDefinitionValue(mixed $value, array $references = []): mixed
    {
        if ($value instanceof ReferenceDefinition) {
            return $this->container->get($value->value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $childReferences = $references;
            $reference = ReflectionReference::fromArrayElement($value, $key);
            if ($reference !== null) {
                $referenceId = $reference->getId();
                if (isset($references[$referenceId])) {
                    throw new InvalidConfigurationException(sprintf(
                        'ClassDefinition arguments contain a recursive array at key "%s".',
                        (string) $key,
                    ));
                }
                $childReferences[$referenceId] = true;
            }
            $resolved[$key] = $this->resolveDefinitionValue($item, $childReferences);
        }

        return $resolved;
    }

    private function resolveFactory(string $id, PreparedFactoryDefinition $definition): callable|LazyServiceFactoryInterface
    {
        $factory = $definition->factory;

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
        if ($definition->validateResolvedCallable
            && !$factory instanceof LazyServiceFactoryInterface
        ) {
            FactorySpecificationValidator::assertResolvedCallable($id, $factory);
        }

        return $factory;
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        if ($definition instanceof ClassDefinition) {
            $snapshots = [];
            $definition = new ClassDefinition(
                $definition->value,
                self::snapshotDefinitionArray($definition->constructorParams, $snapshots),
                self::snapshotDefinitionArray($definition->methodCalls, $snapshots),
                $definition->autowire,
            );
            FactorySpecificationValidator::assertValid($id, $definition);
            return $definition;
        }
        $factory = match (true) {
            $definition instanceof FactoryDefinition => $definition->value,
            $definition instanceof ConfiguredFactoryDefinition => $definition->factory,
            default => throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class),
        };
        if (is_array($factory)) {
            // Readonly arrays can still contain caller-owned references. Snapshot
            // routing slots while preserving the factory object's business state.
            $snapshot = [];
            foreach ($factory as $key => $value) {
                $snapshot[$key] = $value;
            }
            $factory = $snapshot;
        }
        $validateResolved = is_string($factory) || !is_callable($factory);
        FactorySpecificationValidator::assertValid($id, $factory);
        return new PreparedFactoryDefinition($factory, $validateResolved);
    }

    /**
     * @template T of array<array-key, mixed>
     * @param T $values
     * @param array<string, array<array-key, mixed>> $snapshots
     * @return T
     */
    private static function snapshotDefinitionArray(array $values, array &$snapshots): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            if (!is_array($value)) {
                $snapshot[$key] = $value;
                continue;
            }
            $reference = ReflectionReference::fromArrayElement($values, $key);
            if ($reference === null) {
                $snapshot[$key] = self::snapshotDefinitionArray($value, $snapshots);
                continue;
            }

            $referenceId = $reference->getId();
            if (!array_key_exists($referenceId, $snapshots)) {
                // Preserve cycles inside the owned graph. They remain errors only
                // when resolved, so runtime overrides can bypass unused defaults.
                $snapshots[$referenceId] = [];
                $snapshots[$referenceId] = self::snapshotDefinitionArray($value, $snapshots);
            }
            $snapshot[$key] = &$snapshots[$referenceId];
        }

        /** @var T $snapshot Array shape and values are unchanged; only reference ownership differs. */
        return $snapshot;
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition
            || $definition instanceof ConfiguredFactoryDefinition;
    }
}
