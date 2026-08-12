<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Resolver\Entry\EntryClassEligibility;
use Componenta\DI\Resolver\TypeHints;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Expands explicit roots through statically knowable dependencies. */
final readonly class AutowireClassGraph
{
    /** @param array<string, non-empty-string> $aliases */
    public function __construct(private array $aliases = []) {}

    /**
     * @param iterable<AutowireEntry|class-string> $roots
     * @param array<string, true> $excluded
     * @return list<class-string>
     */
    public function expand(iterable $roots, array $excluded = []): array
    {
        $pending = [];

        foreach ($roots as $root) {
            $class = $root instanceof AutowireEntry ? $root->class : $root;

            if (!is_string($class) || $class === '') {
                throw new InvalidArgumentException('Autowire entry must contain a non-empty class-string.');
            }

            $pending[$class] = true;
        }

        $result = [];

        while ($pending !== []) {
            $class = array_key_first($pending);
            unset($pending[$class]);

            if (isset($result[$class]) || isset($excluded[$class])) {
                continue;
            }

            if (!class_exists($class)
                && !interface_exists($class)
                && !trait_exists($class)
                && !enum_exists($class)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot compile autowire entry "%s": class is not loadable.',
                    $class,
                ));
            }

            $reflection = new ReflectionClass($class);
            if (!EntryClassEligibility::allows($reflection)) {
                continue;
            }

            $class = $reflection->getName();
            $result[$class] = true;

            foreach ($this->dependencies($reflection) as $dependency) {
                if (!isset($result[$dependency]) && !isset($excluded[$dependency])) {
                    $pending[$dependency] = true;
                }
            }
        }

        $classes = array_keys($result);
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<class-string>
     */
    private function dependencies(ReflectionClass $class): array
    {
        $dependencies = [];
        $constructorDisabled = $class->getAttributes(
            NoConstructor::class,
            ReflectionAttribute::IS_INSTANCEOF,
        ) !== [];
        $constructor = $class->getConstructor();

        if (!$constructorDisabled && $constructor !== null) {
            $this->appendMethodDependencies($dependencies, $constructor);
        }

        foreach (self::properties($class) as $property) {
            if ($property->getAttributes(
                Inject::class,
                ReflectionAttribute::IS_INSTANCEOF,
            ) === []) {
                continue;
            }

            $this->appendDependency(
                $dependencies,
                TypeHints::classOf($property->getType(), $property->getDeclaringClass()),
            );
        }

        foreach ($class->getAttributes(
            SetUp::class,
            ReflectionAttribute::IS_INSTANCEOF,
        ) as $attribute) {
            $setup = $attribute->newInstance();

            if ($class->hasMethod($setup->method)) {
                $this->appendMethodDependencies($dependencies, $class->getMethod($setup->method));
            }
        }

        return array_keys($dependencies);
    }

    /**
     * ReflectionClass::getProperties() omits private properties declared by
     * ancestors. AttributeProcessor intentionally includes them, so the
     * compilation graph must use the same hierarchy view.
     *
     * @param ReflectionClass<object> $class
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $class): array
    {
        $properties = $class->getProperties();

        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
                if ($property->getDeclaringClass()->getName() === $parent->getName()) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }

    /** @param array<class-string, true> $dependencies */
    private function appendMethodDependencies(array &$dependencies, ReflectionMethod $method): void
    {
        foreach ($method->getParameters() as $parameter) {
            $this->appendDependency(
                $dependencies,
                TypeHints::classOf($parameter->getType(), $parameter->getDeclaringClass()),
            );
        }
    }

    /** @param array<class-string, true> $dependencies */
    private function appendDependency(array &$dependencies, ?string $dependency): void
    {
        if ($dependency === null) {
            return;
        }

        $dependency = $this->resolveAlias($dependency);
        if (!class_exists($dependency)) {
            return;
        }

        $candidate = new ReflectionClass($dependency);
        if (EntryClassEligibility::allows($candidate)) {
            $dependencies[$candidate->getName()] = true;
        }
    }

    private function resolveAlias(string $id): string
    {
        $seen = [];

        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot compile autowire graph through cyclic alias "%s".',
                    $id,
                ));
            }

            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }
}
