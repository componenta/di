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
        if ($factory instanceof FactoryDefinition
            || $factory instanceof ClassDefinition
        ) {
            return;
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
        if (!is_array($factory)
            || array_keys($factory) !== [0, 1]
            || !is_string($factory[1])
            || $factory[1] === ''
        ) {
            return false;
        }

        if (is_object($factory[0])) {
            return method_exists($factory[0], $factory[1]);
        }

        // A string owner may be an opaque service id. Its object and method
        // cannot be validated until the container resolves that service.
        return is_string($factory[0]) && $factory[0] !== '';
    }

    private function __construct() {}
}
