<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Componenta\DI\Resolver\InjectionTargetInterface;
use Componenta\Reflection\Reflection;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * {@see InjectionTargetInterface} adapter over a {@see ReflectionProperty}.
 *
 * Properties do not carry positional information or declared defaults in the
 * same sense parameters do, so {@see self::getPosition()} always returns
 * `null`. {@see self::isDefaultValueAvailable()} reports whether the property
 * has a declared default; {@see self::getDefaultValue()} returns it (PHP's
 * native {@see ReflectionProperty::getDefaultValue()}).
 */
final readonly class PropertyTarget implements InjectionTargetInterface
{
    public function __construct(
        private ReflectionProperty $property,
    ) {}

    public function getName(): string
    {
        return $this->property->getName();
    }

    public function getType(): ?ReflectionType
    {
        return $this->property->getType();
    }

    public function allowsNull(): bool
    {
        return $this->property->getType()?->allowsNull() ?? true;
    }

    public function isDefaultValueAvailable(): bool
    {
        return $this->property->hasDefaultValue();
    }

    public function getDefaultValue(): mixed
    {
        return $this->property->getDefaultValue();
    }

    public function getPosition(): ?int
    {
        return null;
    }

    public function getDeclaringContext(): string
    {
        return sprintf(
            '%s::$%s',
            $this->property->getDeclaringClass()->getName(),
            $this->property->getName(),
        );
    }

    public function getFirstAttribute(string $attributeClass): ?object
    {
        return Reflection::getFirstMetadata($this->property, $attributeClass);
    }

    public function getAttributes(?string $attributeClass = null): ?array
    {
        return Reflection::getMetadata($this->property, $attributeClass);
    }

    public function getReflector(): ReflectionParameter|ReflectionProperty
    {
        return $this->property;
    }
}
