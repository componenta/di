<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\NullContainer;
use Componenta\Reflection\Reflection;
use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use WeakMap;

/** Validates factory forms and their runtime `(ContainerValue, array)` ABI. */
final class FactorySpecificationValidator
{
    /** @var WeakMap<ReflectionFunctionAbstract,true>|null */
    private static ?WeakMap $validatedCallables = null;
    private static ?ContainerValue $containerArgument = null;

    public static function assertValid(string $id, mixed $factory): void
    {
        if ($factory instanceof FactoryDefinition) {
            self::assertKnownCallable($id, $factory->value);
            return;
        }
        if ($factory instanceof ClassDefinition) {
            self::assertClassDefinition($id, $factory);
            return;
        }
        if ($factory instanceof CompiledFactoryDefinition) {
            if (CompiledFactoryDefinition::decode($factory->encode()) !== null) {
                return;
            }
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory definition for "%s" is malformed.',
                $id,
            ));
        }
        if (CompiledFactoryDefinition::isEncodedValue($factory)) {
            if (CompiledFactoryDefinition::decode($factory) !== null) {
                return;
            }
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" contains malformed compiled metadata.',
                $id,
            ));
        }

        // String callables are ambiguous with service ids. FactoryResolver gives
        // an existing service id precedence and validates the resolved callable.
        if (is_string($factory) && $factory !== '') {
            return;
        }
        if (is_callable($factory)) {
            self::assertResolvedCallable($id, $factory);
            return;
        }
        if (self::isDeferredCallable($factory)) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Factory "%s" has unsupported type %s.',
            $id,
            get_debug_type($factory),
        ));
    }

    public static function assertResolvedCallable(string $id, callable $factory): void
    {
        if ($factory instanceof LazyServiceFactoryInterface) {
            return;
        }

        try {
            $reflection = Reflection::callable($factory);
        } catch (InvalidArgumentException) {
            return;
        }

        $validated = self::$validatedCallables ??= new WeakMap();
        if (isset($validated[$reflection])) {
            return;
        }

        if (self::isMagicClosureTrampoline($reflection)) {
            $validated[$reflection] = true;
            return;
        }

        self::assertArgumentCount($id, $reflection);

        /** @var list<ReflectionParameter> $parameters */
        $parameters = array_values($reflection->getParameters());
        $scope = self::callableScope($reflection);
        self::assertArgument($id, $parameters, 0, self::containerArgument(), $scope);
        self::assertArgument($id, $parameters, 1, [], $scope);

        $validated[$reflection] = true;
    }

    private static function assertKnownCallable(string $id, callable $factory): void
    {
        if (!is_string($factory)) {
            self::assertResolvedCallable($id, $factory);
        }
    }

    private static function assertArgumentCount(string $id, ReflectionFunctionAbstract $reflection): void
    {
        $required = $reflection->getNumberOfRequiredParameters();
        if ($required > 2) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" requires %d arguments, but the factory runtime supplies 2.',
                $id,
                $required,
            ));
        }

        if ($reflection->isInternal()
            && !$reflection->isVariadic()
            && $reflection->getNumberOfParameters() < 2
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" internal callable accepts at most %d arguments, but the factory runtime supplies 2.',
                $id,
                $reflection->getNumberOfParameters(),
            ));
        }
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param ReflectionClass<object>|null $scope
     */
    private static function assertArgument(
        string $id,
        array $parameters,
        int $position,
        mixed $argument,
        ?ReflectionClass $scope,
    ): void {
        $parameter = self::parameterAt($parameters, $position);
        if ($parameter === null || $parameter->getType() === null) {
            return;
        }

        try {
            $valid = ReflectionType::match(
                $parameter->getType(),
                $argument,
                strict: true,
                scope: $scope,
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" parameter #%d type cannot be validated: %s',
                $id,
                $position + 1,
                $e->getMessage(),
            ), previous: $e);
        }

        if (!$valid) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" parameter #%d ($%s) type "%s" is incompatible with runtime argument %s.',
                $id,
                $position + 1,
                $parameter->getName(),
                (string) $parameter->getType(),
                get_debug_type($argument),
            ));
        }
    }

    /** @param list<ReflectionParameter> $parameters */
    private static function parameterAt(array $parameters, int $position): ?ReflectionParameter
    {
        if (isset($parameters[$position])) {
            return $parameters[$position];
        }
        if ($parameters === []) {
            return null;
        }

        $last = $parameters[count($parameters) - 1];
        return $last->isVariadic() ? $last : null;
    }

    /** @return ReflectionClass<object>|null */
    private static function callableScope(ReflectionFunctionAbstract $reflection): ?ReflectionClass
    {
        if ($reflection instanceof ReflectionMethod) {
            return $reflection->getDeclaringClass();
        }
        if ($reflection instanceof ReflectionFunction) {
            return $reflection->getClosureScopeClass();
        }
        return null;
    }

    private static function isMagicClosureTrampoline(ReflectionFunctionAbstract $reflection): bool
    {
        if (!$reflection instanceof ReflectionFunction || !$reflection->isInternal()) {
            return false;
        }

        $name = $reflection->getName();
        $scope = $reflection->getClosureScopeClass();
        if ($scope !== null && $scope->hasMethod($name)) {
            $method = $scope->getMethod($name);
            if ($method->isInternal()
                && $method->getDeclaringClass()->getName() === $scope->getName()
            ) {
                return false;
            }
        }

        $bound = $reflection->getClosureThis();
        if ($bound !== null) {
            $candidate = [$bound, $name];
        } else {
            $class = $reflection->getClosureCalledClass() ?? $scope;
            if ($class === null) {
                return false;
            }
            $candidate = [$class->getName(), $name];
        }

        if (!is_callable($candidate)) {
            return false;
        }

        try {
            Reflection::callable($candidate);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    }

    private static function containerArgument(): ContainerValue
    {
        return self::$containerArgument ??= new ContainerValue(new NullContainer());
    }

    private static function assertClassDefinition(string $id, ClassDefinition $definition): void
    {
        $className = $definition->value;
        if (!class_exists($className) && !interface_exists($className)) {
            throw new InvalidConfigurationException(sprintf(
                'ClassDefinition for "%s" references unknown class "%s".',
                $id,
                $className,
            ));
        }

        /** @var class-string $className */
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass($className);
        if (!$class->isInstantiable()) {
            throw new InvalidConfigurationException(sprintf(
                'ClassDefinition for "%s" targets non-instantiable class "%s".',
                $id,
                $className,
            ));
        }

        $magicCall = $class->hasMethod('__call') && $class->getMethod('__call')->isPublic();
        foreach ($definition->methodCalls as $index => $call) {
            if (!is_array($call)
                || !array_key_exists('method', $call)
                || !is_string($call['method'])
                || $call['method'] === ''
                || !array_key_exists('params', $call)
                || !is_array($call['params'])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'ClassDefinition for "%s" contains malformed method call #%d.',
                    $id,
                    $index,
                ));
            }

            $method = $call['method'];
            if (!$class->hasMethod($method)) {
                if ($magicCall) {
                    continue;
                }
                throw new InvalidConfigurationException(sprintf(
                    'ClassDefinition for "%s" calls missing method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }

            if (!$class->getMethod($method)->isPublic() && !$magicCall) {
                throw new InvalidConfigurationException(sprintf(
                    'ClassDefinition for "%s" calls non-public method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }
        }
    }

    private static function isDeferredCallable(mixed $factory): bool
    {
        return is_array($factory)
            && array_keys($factory) === [0, 1]
            && is_string($factory[0])
            && $factory[0] !== ''
            && is_string($factory[1])
            && $factory[1] !== '';
    }

    private function __construct() {}
}
