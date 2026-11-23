<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Componenta\DI\Internal\ReflectionSnapshot;
use Componenta\DI\Internal\Resolver\Parameter\ParameterDeclaration;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetScope;
use Componenta\DI\Resolver\TypeHints;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use WeakReference;

use function Componenta\DI\Internal\is_attribute_class_loaded;
use function Componenta\DI\Internal\is_closure_reflector;

/**
 * Immutable, precomputed view of one reflected parameter.
 */
final class ParameterTarget extends ParameterTargetScope
{
    /** Native reflectors returned to extensions are independent of the stored declaration. */
    public ReflectionParameter $reflection {
        get => $this->declaration->snapshot();
    }

    /** Evaluates the declared default anew, including object defaults. */
    public mixed $defaultValue {
        get => $this->declaration->defaultValue();
    }

    /** @var list<class-string> */
    public array $attributeClasses {
        get => $this->declaration->attributeClasses;
    }

    /** @var list<class-string> */
    public readonly array $typeNames;

    /** @var class-string|null */
    public readonly ?string $className;

    /** @var ReflectionClass<object>|null */
    private readonly ?ReflectionClass $declaringClass;
    /** @var list<class-string>|null */
    private ?array $unionCandidates = null;

    public readonly string $name;
    public readonly int $position;
    public readonly ?ReflectionType $type;
    public readonly bool $allowsNull;
    public readonly bool $hasDefault;
    public readonly bool $variadic;
    public readonly bool $byReference;
    public readonly string $declaringContext;

    public function __construct(ReflectionParameter $reflection)
    {
        $source = $reflection;
        $reflection = ReflectionSnapshot::parameter($source, forPublication: true);
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
        $native = $source::class === ReflectionParameter::class;
        $nativeOmission = $native ? !$this->variadic && $reflection->isOptional() && $function->isInternal() : null;
        $closure = is_closure_reflector($function);
        $canCacheClassification = $native && !$closure;
        $closureMethodSource = $closure && $function instanceof ReflectionMethod ? WeakReference::create($source) : null;
        $this->declaringContext = self::declaringContext($reflection, $function);
        parent::__construct(new ParameterDeclaration(
            $reflection,
            $native ? null : $source,
            $nativeOmission,
            $canCacheClassification,
            $closureMethodSource,
        ));
    }

    public function hasAttribute(string $attributeClass): bool
    {
        if (isset($this->declaration->attributesByClass[strtolower($attributeClass)])) {
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

        $this->attributeObserver?->record($this, $attributeClass);
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
        $attributes = $this->declaration->attributeReflectors;
        foreach ($attributes as $attribute) {
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
            foreach ($attributes as $attribute) {
                $class = $attribute->getName();
                if (is_attribute_class_loaded($class) && is_a($class, $attributeClass, true)) {
                    $reflector = $attribute;
                    break;
                }
            }
        }

        if ($reflector === null) {
            $this->attributeObserver?->record($this, $attributeClass);
            return null;
        }

        /** @var T $instance */
        $instance = $reflector->newInstance();

        return $instance;
    }

    /**
     * Records a missing attribute name without invoking an autoloader.
     * Applies during supports() for a parameter with declared attributes.
     */
    public function watchAttributeAvailability(string $attributeClass): void
    {
        if ($attributeClass !== '' && $this->attributeClasses !== []) {
            $this->attributeObserver?->record($this, $attributeClass);
        }
    }

    public function __clone()
    {
        $this->attributeObserver = null;
    }

    public function accepts(mixed $value): bool
    {
        return TypeHints::matches($this->type, $value, $this->declaringClass);
    }

    /** @return list<class-string> */
    public function unionCandidates(): array
    {
        return $this->unionCandidates ??= TypeHints::simpleUnionClassNames($this->type, $this->declaringClass);
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
