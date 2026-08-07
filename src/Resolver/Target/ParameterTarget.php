<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use ReflectionAttribute;
use Componenta\DI\Resolver\TypeHints;
use ReflectionClass;
use ReflectionParameter;
use ReflectionType;

/** Immutable, precomputed view of one reflected parameter. */
final class ParameterTarget
{
    /** @var list<ReflectionAttribute<object>> */
    private readonly array $attributeReflectors;

    /** @var array<class-string, list<ReflectionAttribute<object>>> */
    private readonly array $attributesByClass;

    /** @var list<class-string> */
    public readonly array $attributeClasses;

    /** @var list<class-string> */
    public readonly array $typeNames;

    /** One concrete named class/interface type, when the declaration has one. */
    public readonly ?string $className;

    private readonly ?ReflectionClass $declaringClass;

    public readonly string $name;

    public readonly int $position;

    public readonly ?ReflectionType $type;

    public readonly bool $allowsNull;

    public readonly bool $hasDefault;

    /**
     * Reflection defaults are read on demand. This matters for `new Foo()`
     * defaults: PHP creates a fresh object for every invocation, while caching
     * getDefaultValue() in the target would incorrectly share one instance.
     */
    public mixed $default {
        get => $this->hasDefault ? $this->reflection->getDefaultValue() : null;
    }

    public readonly bool $variadic;

    public readonly bool $byReference;

    public readonly string $declaringContext;

    public function __construct(
        public readonly ReflectionParameter $reflection,
    ) {
        $this->name = $reflection->getName();
        $this->position = $reflection->getPosition();
        $this->type = $reflection->getType();
        $this->declaringClass = $reflection->getDeclaringClass();
        $this->typeNames = TypeHints::classNames($this->type, $this->declaringClass);
        $this->className = TypeHints::classOf($this->type, $this->declaringClass);
        $this->allowsNull = $reflection->allowsNull();
        $this->hasDefault = $reflection->isDefaultValueAvailable();
        $this->variadic = $reflection->isVariadic();
        $this->byReference = $reflection->isPassedByReference();
        $this->declaringContext = self::declaringContext($reflection);

        $allAttributes = [];
        $attributes = [];
        $classes = [];

        foreach ($reflection->getAttributes() as $attribute) {
            /** @var class-string $class */
            $class = $attribute->getName();
            $allAttributes[] = $attribute;
            $attributes[$class][] = $attribute;
            $classes[$class] = true;
        }

        $this->attributeReflectors = $allAttributes;
        $this->attributesByClass = $attributes;
        $this->attributeClasses = array_keys($classes);
    }

    public function hasAttribute(string $attributeClass): bool
    {
        if (isset($this->attributesByClass[$attributeClass])) {
            return true;
        }

        foreach ($this->attributeClasses as $class) {
            if (is_a($class, $attributeClass, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    public function firstAttribute(string $attributeClass): ?object
    {
        return ($this->attributeReflectors($attributeClass)[0] ?? null)?->newInstance();
    }

    /** @return list<ReflectionAttribute<object>> */
    private function attributeReflectors(string $attributeClass): array
    {
        $attributes = [];

        // Preserve native declaration order across exact and inherited
        // matches. Grouping by concrete class first would incorrectly prefer
        // an exact base attribute declared later over an earlier subclass.
        foreach ($this->attributeReflectors as $attribute) {
            if (is_a($attribute->getName(), $attributeClass, true)) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /** Whether a value satisfies this parameter's declared lexical type. */
    public function accepts(mixed $value): bool
    {
        return TypeHints::matches($this->type, $value, $this->declaringClass);
    }

    private static function declaringContext(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();
        $class = $parameter->getDeclaringClass();

        if ($class !== null) {
            return sprintf('%s::%s()', $class->getName(), $function->getName());
        }

        return $function->isClosure()
            ? 'Closure'
            : sprintf('%s()', $function->getName());
    }
}
