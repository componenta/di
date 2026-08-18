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
use Componenta\DI\ResolutionContext;
use Componenta\Reflection\Reflection;
use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;

/** Validates v5 factory forms and their runtime `(ContainerValue, ResolutionContext)` ABI. */
final class FactorySpecificationValidator
{
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
            throw new InvalidConfigurationException(sprintf('Compiled factory definition for "%s" is malformed.', $id));
        }
        if (CompiledFactoryDefinition::isEncodedValue($factory)) {
            if (CompiledFactoryDefinition::decode($factory) !== null) {
                return;
            }
            throw new InvalidConfigurationException(sprintf('Factory "%s" contains malformed compiled metadata.', $id));
        }
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

        throw new InvalidConfigurationException(sprintf('Factory "%s" has unsupported type %s.', $id, get_debug_type($factory)));
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

        if ($reflection->getNumberOfRequiredParameters() > 2) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" requires more than the two runtime arguments ContainerValue and ResolutionContext.',
                $id,
            ));
        }

        $parameters = $reflection->getParameters();
        $scope = $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass() : null;
        self::assertArgument($id, $parameters, 0, self::containerArgument(), $scope);
        self::assertArgument($id, $parameters, 1, new ResolutionContext(), $scope);
    }

    private static function assertKnownCallable(string $id, callable $factory): void
    {
        if (!is_string($factory)) {
            self::assertResolvedCallable($id, $factory);
        }
    }

    /** @param list<ReflectionParameter> $parameters @param ReflectionClass<object>|null $scope */
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
            $valid = ReflectionType::match($parameter->getType(), $argument, strict: true, scope: $scope);
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
        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic() || $parameter->getPosition() === $position) {
                return $parameter;
            }
        }
        return null;
    }

    private static function containerArgument(): ContainerValue
    {
        return self::$containerArgument ??= new ContainerValue(new NullContainer());
    }

    private static function assertClassDefinition(string $id, ClassDefinition $definition): void
    {
        if (!class_exists($definition->value)) {
            throw new InvalidConfigurationException(sprintf('ClassDefinition for "%s" references unknown class "%s".', $id, $definition->value));
        }
        foreach ($definition->methodCalls as $call) {
            if (!isset($call['method']) || !is_string($call['method']) || $call['method'] === '') {
                throw new InvalidConfigurationException(sprintf('ClassDefinition for "%s" contains an invalid method call.', $id));
            }
        }
    }

    private static function isDeferredCallable(mixed $factory): bool
    {
        return is_array($factory)
            && array_keys($factory) === [0, 1]
            && (is_string($factory[0]) || is_object($factory[0]))
            && is_string($factory[1])
            && $factory[1] !== '';
    }

    private function __construct() {}
}
