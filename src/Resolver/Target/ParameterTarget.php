<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Componenta\DI\Resolver\InjectionTargetInterface;
use Componenta\Reflection\Reflection;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * {@see InjectionTargetInterface} adapter over a {@see ReflectionParameter}.
 *
 * Forwards lookup methods to the underlying reflector and delegates attribute
 * access through the cached {@see Reflection} helpers.
 */
final readonly class ParameterTarget implements InjectionTargetInterface
{
    public function __construct(
        private ReflectionParameter $parameter,
    ) {}

    public function getName(): string
    {
        return $this->parameter->getName();
    }

    public function getType(): ?ReflectionType
    {
        return $this->parameter->getType();
    }

    public function allowsNull(): bool
    {
        return $this->parameter->allowsNull();
    }

    public function isDefaultValueAvailable(): bool
    {
        return $this->parameter->isDefaultValueAvailable();
    }

    public function getDefaultValue(): mixed
    {
        return $this->parameter->getDefaultValue();
    }

    public function getPosition(): ?int
    {
        return $this->parameter->getPosition();
    }

    public function getDeclaringContext(): string
    {
        $function = $this->parameter->getDeclaringFunction();
        $class    = $this->parameter->getDeclaringClass();

        if ($class !== null) {
            return sprintf('%s::%s()', $class->getName(), $function->getName());
        }

        if ($function->isClosure()) {
            return 'Closure';
        }

        return sprintf('%s()', $function->getName());
    }

    public function getFirstAttribute(string $attributeClass): ?object
    {
        return Reflection::getFirstMetadata($this->parameter, $attributeClass);
    }

    public function getAttributes(?string $attributeClass = null): ?array
    {
        return Reflection::getMetadata($this->parameter, $attributeClass);
    }

    public function getReflector(): ReflectionParameter|ReflectionProperty
    {
        return $this->parameter;
    }
}
