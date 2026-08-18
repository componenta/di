<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Componenta\DI\Resolver\TypeHints;
use ReflectionClass;
use ReflectionProperty;
use ReflectionType;
use Reflector;

/** Immutable, precomputed value metadata for one reflected property. */
final class PropertyTarget implements ValueTargetInterface
{
    /** @var list<class-string> */
    public readonly array $typeNames;

    /** @var class-string|null */
    public readonly ?string $className;

    /** @var ReflectionClass<object> */
    private readonly ReflectionClass $declaringClass;

    public readonly string $name;
    public readonly ?ReflectionType $type;
    public readonly bool $allowsNull;
    public readonly string $declaringContext;

    public function __construct(public readonly ReflectionProperty $reflection)
    {
        $this->name = $reflection->getName();
        $this->type = $reflection->getType();
        $this->declaringClass = $reflection->getDeclaringClass();
        $this->typeNames = TypeHints::classNames($this->type, $this->declaringClass);
        $this->className = TypeHints::classOf($this->type, $this->declaringClass);
        $this->allowsNull = $this->type?->allowsNull() ?? true;
        $this->declaringContext = sprintf('%s::$%s', $this->declaringClass->getName(), $this->name);
    }

    public function reflector(): Reflector
    {
        return $this->reflection;
    }

    public function accepts(mixed $value): bool
    {
        return TypeHints::matches($this->type, $value, $this->declaringClass);
    }
}
