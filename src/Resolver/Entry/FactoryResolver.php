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
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
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
use Throwable;

/** Resolves configured factories and class definitions. */
final class FactoryResolver implements DefinitionAwareResolverInterface
{
    /** @var array<string,true> */
    private array $validateResolvedFactories = [];

    private readonly AutowireByTypeResolver $autowire;
    private readonly ParameterTargetFactory $targets;

    /** @param array<string,mixed> $factories */
    public function __construct(
        private array $factories,
        private readonly ContainerInterface $container,
        private readonly ProxyFactoryInterface $proxyFactory,
    ) {
        $this->autowire = new AutowireByTypeResolver($container);
        $this->targets = new ParameterTargetFactory();

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
            $definition = $this->factories[$id];
            if ($definition instanceof ClassDefinition) {
                return $this->classDefinition($definition, $params);
            }

            $factory = $this->resolveFactory($id);
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

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if (!$this->supportsDefinition($definition)) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        FactorySpecificationValidator::assertValid($id, $definition);
        $this->factories[$id] = $definition instanceof FactoryDefinition
            ? $definition->value
            : $definition;
        unset($this->validateResolvedFactories[$id]);

        if ($definition instanceof FactoryDefinition
            && (is_string($definition->value) || !is_callable($definition->value))
        ) {
            $this->validateResolvedFactories[$id] = true;
        }
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof FactoryDefinition
            || $definition instanceof ClassDefinition;
    }
}
