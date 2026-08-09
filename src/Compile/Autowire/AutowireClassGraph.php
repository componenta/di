<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Resolver\Entry\EntryClassEligibility;
use Componenta\DI\Resolver\TypeHints;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/** Expands explicit roots through statically knowable constructor and injection dependencies. */
final readonly class AutowireClassGraph
{
    /**
     * @param iterable<AutowireEntry|class-string> $roots
     * @param array<string, true> $excluded Existing explicit bindings which must keep ownership.
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

    /** @return list<class-string> */
    private function dependencies(ReflectionClass $class): array
    {
        $dependencies = [];
        $constructor = $class->getConstructor();

        if ($constructor !== null) {
            $this->appendMethodDependencies($dependencies, $constructor);
        }

        foreach ($class->getProperties() as $property) {
            if ($property->getAttributes(Inject::class) === []) {
                continue;
            }

            $dependency = TypeHints::classOf($property->getType(), $property->getDeclaringClass());
            if ($dependency !== null && class_exists($dependency)) {
                $candidate = new ReflectionClass($dependency);
                if (EntryClassEligibility::allows($candidate)) {
                    $dependencies[$candidate->getName()] = true;
                }
            }
        }

        foreach ($class->getAttributes(SetUp::class) as $attribute) {
            $setup = $attribute->newInstance();

            if ($class->hasMethod($setup->method)) {
                $this->appendMethodDependencies($dependencies, $class->getMethod($setup->method));
            }
        }

        return array_keys($dependencies);
    }

    /** @param array<class-string, true> $dependencies */
    private function appendMethodDependencies(array &$dependencies, ReflectionMethod $method): void
    {
        foreach ($method->getParameters() as $parameter) {
            $dependency = TypeHints::classOf($parameter->getType(), $parameter->getDeclaringClass());

            if ($dependency !== null && class_exists($dependency)) {
                $candidate = new ReflectionClass($dependency);
                if (EntryClassEligibility::allows($candidate)) {
                    $dependencies[$candidate->getName()] = true;
                }
            }
        }
    }
}
