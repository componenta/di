<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Returns the ReflectionParameter at the given position on a fixture method.
 */
function typedParam(string $method, int $index, string $class = TypedParameters::class): ReflectionParameter
{
    return (new ReflectionMethod($class, $method))->getParameters()[$index];
}

/**
 * Returns a ReflectionProperty for a fixture class property.
 */
function typedProperty(string $class, string $name): ReflectionProperty
{
    return new ReflectionProperty($class, $name);
}
