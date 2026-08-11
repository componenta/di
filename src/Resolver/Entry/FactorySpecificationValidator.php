<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use ReflectionClass;

/** Validates the public configuration forms accepted by FactoryResolver. */
final class FactorySpecificationValidator
{
    public static function assertValid(string $id, mixed $factory): void
    {
        if ($factory instanceof FactoryDefinition) {
            return;
        }

        if ($factory instanceof ClassDefinition) {
            self::assertValidClassDefinition($id, $factory);
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
                'Factory "%s" contains a malformed compiled factory definition.',
                $id,
            ));
        }

        if (is_callable($factory)) {
            return;
        }

        if (is_string($factory) && $factory !== '') {
            return;
        }

        if (self::isDeferredCallable($factory)) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Factory "%s" must be callable, a non-empty service id, '
            . '[service|object, method], FactoryDefinition, ClassDefinition '
            . 'or CompiledFactoryDefinition; got %s.',
            $id,
            get_debug_type($factory),
        ));
    }

    private static function assertValidClassDefinition(
        string $id,
        ClassDefinition $definition,
    ): void {
        $className = self::runtimeClassName($definition);

        if (!class_exists($className) && !interface_exists($className)) {
            throw new InvalidConfigurationException(sprintf(
                'Class definition for "%s" targets unavailable class "%s".',
                $id,
                $className,
            ));
        }

        /** @var class-string $className */
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass($className);

        if (!$class->isInstantiable()) {
            throw new InvalidConfigurationException(sprintf(
                'Class definition for "%s" targets non-instantiable class "%s".',
                $id,
                $className,
            ));
        }

        $magicCall = $class->hasMethod('__call')
            && $class->getMethod('__call')->isPublic();

        foreach ($definition->methodCalls as $index => $call) {
            if (!is_array($call)
                || !array_key_exists('method', $call)
                || !is_string($call['method'])
                || $call['method'] === ''
                || !array_key_exists('params', $call)
                || !is_array($call['params'])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Class definition for "%s" contains malformed method call #%d; '
                    . 'expected array{method: non-empty-string, params: array}.',
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
                    'Class definition for "%s" calls missing method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }

            if (!$class->getMethod($method)->isPublic() && !$magicCall) {
                throw new InvalidConfigurationException(sprintf(
                    'Class definition for "%s" calls non-public method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }
        }
    }

    /**
     * Treat the documented class-string as untrusted runtime input at the
     * configuration boundary; PHP cannot enforce the PHPDoc type itself.
     */
    private static function runtimeClassName(ClassDefinition $definition): string
    {
        return $definition->value;
    }

    private static function isDeferredCallable(mixed $factory): bool
    {
        if (!is_array($factory)
            || array_keys($factory) !== [0, 1]
            || !is_string($factory[1])
            || $factory[1] === ''
        ) {
            return false;
        }

        if (is_object($factory[0])) {
            return false;
        }

        // A string owner may be an opaque service id. Its object and method
        // cannot be validated until the container resolves that service.
        return is_string($factory[0]) && $factory[0] !== '';
    }

    private function __construct() {}
}
