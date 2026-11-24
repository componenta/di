<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Closure;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** One composed DI attribute usage together with its semantic definition. */
final class AttributeUsage
{
    /** @var ReflectionAttribute<object> */
    public readonly ReflectionAttribute $reflection;

    /** @var class-string */
    public readonly string $attributeClass;

    /** @var Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty */
    private readonly Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $declaration;

    /** @var ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty */
    public ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target {
        get => ReflectionSnapshot::target($this->declaration);
    }

    /**
     * Declared expressions are evaluated on read, never retained in a shared plan.
     *
     * @var array<array-key, mixed>
     */
    public array $arguments {
        get => $this->reflection->getArguments();
    }

    /**
     * @param ReflectionAttribute<object> $reflection
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     */
    public function __construct(
        ReflectionAttribute $reflection,
        public readonly AttributeDefinition $definition,
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        public readonly int $declarationOrder,
    ) {
        ReflectionSnapshot::assertNativeAttribute($reflection);
        $this->reflection = $reflection;
        $this->attributeClass = $reflection->getName();
        $this->declaration = ReflectionSnapshot::planDeclaration($target);
    }

    /** Creates an isolated runtime attribute instance for one handler invocation. */
    public function newInstance(): object
    {
        return $this->reflection->newInstance();
    }

    /** @param class-string $attribute */
    public function is(string $attribute): bool
    {
        return is_a($this->attributeClass, $attribute, true);
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function hasCapability(string $capability): bool
    {
        foreach ($this->definition->capabilities as $registered) {
            if (is_a($registered, $capability, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string $selector */
    public function matches(string $selector): bool
    {
        return is_a($selector, AttributeCapabilityInterface::class, true)
            ? $this->hasCapability($selector)
            : $this->is($selector);
    }
}
