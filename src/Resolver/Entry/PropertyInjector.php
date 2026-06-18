<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Resolver\Property\PropertiesResolver;
use ReflectionClass;
use ReflectionProperty;

/**
 * Injects resolved values into the instance properties.
 *
 * Delegates value resolution to {@see PropertiesResolver}. Skips properties
 * that must not be written from outside the constructor:
 *  - static properties (class-level shared state)
 *  - promoted properties (owned by the constructor)
 *  - already-initialized readonly properties
 *
 * Policy: only properties for which the resolver chain produces a value (i.e.
 * those with an explicit injection intent - attribute or context key) are
 * written. Default/nullable fallback resolvers are intentionally absent from
 * the default property chain so that declared defaults and constructor
 * assignments are not silently overwritten.
 */
final readonly class PropertyInjector implements PropertyInjectorInterface
{
    public function __construct(
        private PropertiesResolver $propertiesResolver,
    ) {}

    /**
     * @param array<string, mixed> $context Context forwarded to property resolvers.
     */
    public function inject(ReflectionClass $reflector, object $entry, array $context = []): void
    {
        $properties = $this->propertiesResolver->resolve($reflector->getProperties(), $context);

        foreach ($properties as $pair) {
            [$prop, $value] = $pair;

            if ($prop->isStatic()
                || $prop->isPromoted()
                || $this->isReadOnlyInitialized($prop, $entry)
            ) {
                continue;
            }

            $prop->setValue($entry, $value);
        }
    }

    private function isReadOnlyInitialized(ReflectionProperty $property, object $entry): bool
    {
        return $property->isReadOnly() && $property->isInitialized($entry);
    }
}
