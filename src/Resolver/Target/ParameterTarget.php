<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Closure;
use Componenta\DI\Resolver\TypeHints;
use Fiber;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionParameter;
use ReflectionType;
use Reflector;

use function Componenta\DI\Internal\is_closure_reflector;

/** Immutable, precomputed view of one reflected parameter. */
final class ParameterTarget
{
    /** @var list<ReflectionAttribute<object>> */
    private readonly array $attributeReflectors;

    /** @var array<class-string, list<ReflectionAttribute<object>>> */
    private readonly array $attributesByClass;

    /** @var array<int, list<array<array-key, string>>> */
    private array $attributeQueries = [];

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

    public function __construct(public readonly ReflectionParameter $reflection)
    {
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

    public function reflector(): Reflector
    {
        return $this->reflection;
    }

    public function hasAttribute(string $attributeClass): bool
    {
        if (isset($this->attributesByClass[$attributeClass])) {
            return true;
        }

        $needsRefresh = false;
        foreach ($this->attributeClasses as $class) {
            $needsRefresh = $needsRefresh || !self::isAttributeClassLoaded($class);
            if (is_a($class, $attributeClass, true)) {
                return true;
            }
        }

        if ($needsRefresh) {
            // Later discovery can reveal an earlier declaration or an alias
            // for the queried base. Recheck without invoking another loader.
            foreach ($this->attributeClasses as $class) {
                if (self::isAttributeClassLoaded($class) && is_a($class, $attributeClass, true)) {
                    return true;
                }
            }
        }

        $this->recordMissingAttributeQuery($attributeClass);
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
            if (!self::isAttributeClassLoaded($class)) {
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
                if (self::isAttributeClassLoaded($class) && is_a($class, $attributeClass, true)) {
                    $reflector = $attribute;
                    break;
                }
            }
        }

        if ($reflector === null) {
            $this->recordMissingAttributeQuery($attributeClass);
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
            $this->recordMissingAttributeQuery($attributeClass);
        }
    }

    /** @internal Observes availability without invoking an autoloader. */
    public static function isAttributeClassLoaded(string $attributeClass): bool
    {
        return class_exists($attributeClass, false)
            || interface_exists($attributeClass, false)
            || trait_exists($attributeClass, false);
    }

    /**
     * Records unavailable names within one classifier invocation.
     *
     * @internal
     * @template T
     * @param Closure(self):T $classify
     * @return array{T, list<string>}
     */
    public function observeAttributeQueries(Closure $classify): array
    {
        $execution = self::executionId();
        $this->attributeQueries[$execution][] = [];
        try {
            $result = $classify($this);
        } finally {
            $queries = array_pop($this->attributeQueries[$execution]);
            if ($this->attributeQueries[$execution] === []) {
                unset($this->attributeQueries[$execution]);
            }
        }

        return [$result, $queries === [] ? [] : array_values($queries ?? [])];
    }

    private function recordMissingAttributeQuery(string $attributeClass): void
    {
        if ($this->attributeQueries === [] || self::isAttributeClassLoaded($attributeClass)) {
            return;
        }
        $execution = self::executionId();
        $scope = array_key_last($this->attributeQueries[$execution] ?? []);
        if ($scope !== null) {
            $this->attributeQueries[$execution][$scope][$attributeClass] = $attributeClass;
        }
    }

    private static function executionId(): int
    {
        // An observation must not retain an abandoned Fiber.
        $fiber = Fiber::getCurrent();
        return $fiber === null ? 0 : spl_object_id($fiber);
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
