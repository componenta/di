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

        $owner = $factory[0];
        $method = $factory[1];

        if (is_object($owner)) {
            return method_exists($owner, $method);
        }

        if (!is_string($owner) || $owner === '') {
            return false;
        }

        if (class_exists($owner) || interface_exists($owner) || trait_exists($owner)) {
            return method_exists($owner, $method);
        }

        // The string may be an opaque service id whose object type is not
        // knowable until the container resolves it.
        return true;
    }

    private function __construct() {}
}
