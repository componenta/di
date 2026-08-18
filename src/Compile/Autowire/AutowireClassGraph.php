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

/** Expands explicit AOT roots through statically obvious class dependencies. */
final readonly class AutowireClassGraph
{
    /** @var array<string, non-empty-string> */
    private array $aliases;

    /** @param array<string, non-empty-string> $aliases */
    public function __construct(array $aliases = [])
    {
        $this->aliases = $aliases;
    }

    /**
     * @param iterable<AutowireEntry|class-string> $roots
     * @param array<string, true> $excluded
     * @return list<class-string>
     */
    public function expand(iterable $roots, array $excluded = []): array
    {
        /** @var array<class-string, true> $pending */
        $pending = [];
        foreach ($roots as $root) {
            $class = $root instanceof AutowireEntry ? $root->class : $root;
            if ($class === '') {
                throw new InvalidArgumentException('Autowire roots must be non-empty class strings.');
            }
            $resolved = $this->resolveAlias($class);
            if ($resolved !== $class && !class_exists($resolved)) {
                continue;
            }
            if (!class_exists($resolved)) {
                throw new InvalidArgumentException(sprintf('Cannot compile unknown class "%s".', $resolved));
            }
            /** @var class-string $resolved */
            $pending[$resolved] = true;
        }

        /** @var array<class-string, true> $result */
        $result = [];
        while ($pending !== []) {
            /** @var class-string $class */
            $class = array_key_first($pending);
            unset($pending[$class]);
            if (isset($result[$class]) || isset($excluded[$class])) {
                continue;
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($class);
            if (!EntryClassEligibility::allows($reflection)) {
                continue;
            }
            /** @var class-string $class */
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
        /** @var array<class-string, true> $dependencies */
        $dependencies = [];
        $constructorDisabled = $class->getAttributes(NoConstructor::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
        $constructor = $class->getConstructor();
        if (!$constructorDisabled && $constructor !== null) {
            $this->appendMethodDependencies($dependencies, $constructor);
        }

        foreach (self::properties($class) as $property) {
            if ($property->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $this->appendDependency(
                    $dependencies,
                    TypeHints::classOf($property->getType(), $property->getDeclaringClass()),
                );
            }
        }

        foreach ($class->getAttributes(SetUp::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $setup = $attribute->newInstance();
            if ($class->hasMethod($setup->method)) {
                $this->appendMethodDependencies($dependencies, $class->getMethod($setup->method));
            }
        }

        return array_keys($dependencies);
    }

    /**
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
        /** @var class-string $dependency */
        /** @var ReflectionClass<object> $candidate */
        $candidate = new ReflectionClass($dependency);
        if (EntryClassEligibility::allows($candidate)) {
            /** @var class-string $name */
            $name = $candidate->getName();
            $dependencies[$name] = true;
        }
    }

    private function resolveAlias(string $id): string
    {
        /** @var array<string, true> $seen */
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new InvalidArgumentException(sprintf('Cyclic alias "%s" in AOT graph.', $id));
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }
}
