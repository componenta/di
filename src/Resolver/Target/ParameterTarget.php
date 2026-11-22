<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Componenta\DI\Internal\Resolver\Parameter\ParameterAttributeObservation;
use Componenta\DI\Resolver\TypeHints;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionParameter;
use ReflectionType;

use function Componenta\DI\Internal\is_attribute_class_loaded;
use function Componenta\DI\Internal\is_closure_reflector;

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

    /** @var class-string|null */
    public readonly ?string $className;

    /** @var ReflectionClass<object>|null */
    private readonly ?ReflectionClass $declaringClass;

    public readonly string $name;
    public readonly int $position;
    public readonly ?ReflectionType $type;
    public readonly bool $allowsNull;
    public readonly bool $hasDefault;
    public readonly bool $variadic;
    public readonly bool $byReference;
    public readonly string $declaringContext;
    /** @internal Whether the resolver classification can be reused for this target. */
    public readonly bool $canCacheClassification;

    public function __construct(
        public readonly ReflectionParameter $reflection,
        private ?ParameterAttributeObservation $observation = null,
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
        $function = $reflection->getDeclaringFunction();
        $this->canCacheClassification = $reflection::class === ReflectionParameter::class && !is_closure_reflector($function);
        $this->declaringContext = self::declaringContext($reflection, $function);
        unset($function);

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

        $needsRefresh = false;
        foreach ($this->attributeClasses as $class) {
            $needsRefresh = $needsRefresh || !is_attribute_class_loaded($class);
            if (is_a($class, $attributeClass, true)) {
                return true;
            }
        }

        if ($needsRefresh) {
            // Later discovery can reveal an earlier declaration or an alias
            // for the queried base. Recheck without invoking another loader.
            foreach ($this->attributeClasses as $class) {
                if (is_attribute_class_loaded($class) && is_a($class, $attributeClass, true)) {
                    return true;
                }
            }
        }

        $this->observation?->record($this, $attributeClass);
        return false;
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    public function firstAttribute(string $attributeClass): ?object
    {
        $reflector = null;
        $needsRefresh = false;
        foreach ($this->attributeReflectors as $attribute) {
            $class = $attribute->getName();
            if (!is_attribute_class_loaded($class)) {
                $needsRefresh = true;
            }
            if (is_a($class, $attributeClass, true)) {
                $reflector ??= $attribute;
            }
        }

        if ($needsRefresh) {
            // Complete discovery before selecting again in declaration order.
            // Loaded-only checks cannot execute another autoloader.
            $reflector = null;
            foreach ($this->attributeReflectors as $attribute) {
                $class = $attribute->getName();
                if (is_attribute_class_loaded($class) && is_a($class, $attributeClass, true)) {
                    $reflector = $attribute;
                    break;
                }
            }
        }

        if ($reflector === null) {
            $this->observation?->record($this, $attributeClass);
            return null;
        }

        /** @var T $instance */
        $instance = $reflector->newInstance();

        return $instance;
    }

    /** Records a missing attribute name during the current supports() check without invoking an autoloader. */
    public function watchAttributeAvailability(string $attributeClass): void
    {
        if ($attributeClass !== '' && $this->attributeClasses !== []) {
            $this->observation?->record($this, $attributeClass);
        }
    }

    /** @internal A separately owned target gets its own view for observation. */
    public function forObservation(ParameterAttributeObservation $observation): self
    {
        if ($this->observation === $observation) {
            return $this;
        }
        $target = clone $this;
        $target->observation = $observation;
        return $target;
    }

    public function accepts(mixed $value): bool
    {
        return TypeHints::matches($this->type, $value, $this->declaringClass);
    }

    private static function declaringContext(ReflectionParameter $parameter, ReflectionFunctionAbstract $function): string
    {
        if ($function->isClosure()) {
            return 'Closure';
        }

        $class = $parameter->getDeclaringClass();
        return $class === null
            ? sprintf('%s()', $function->getName())
            : sprintf('%s::%s()', $class->getName(), $function->getName());
    }
}
