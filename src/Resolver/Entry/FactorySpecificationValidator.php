<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;

/** Validates the public configuration forms accepted by FactoryResolver. */
final class FactorySpecificationValidator
{
    public static function assertValid(string $id, mixed $factory): void
    {
        if ($factory instanceof FactoryDefinition) {
            return;
        }

        if ($factory instanceof ClassDefinition) {
            if ($factory->value !== '') {
                return;
            }

            throw new InvalidConfigurationException(sprintf(
                'Class factory definition for "%s" requires a non-empty class name.',
                $id,
            ));
        }

        if ($factory instanceof CompiledFactoryDefinition) {
            if ($factory->file !== '' && $factory->class !== '' && $factory->method !== '') {
                return;
            }

            throw new InvalidConfigurationException(sprintf(
                'Compiled factory definition for "%s" requires non-empty file, class and method values.',
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

    private static function isDeferredCallable(mixed $factory): bool
    {
        return is_array($factory)
            && array_keys($factory) === [0, 1]
            && ((is_string($factory[0]) && $factory[0] !== '') || is_object($factory[0]))
            && is_string($factory[1])
            && $factory[1] !== '';
    }

    private function __construct() {}
}
