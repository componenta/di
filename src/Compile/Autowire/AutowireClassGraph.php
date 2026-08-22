<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\TypeHints;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

use function Componenta\DI\Internal\is_entry_class_eligible;

/** Expands explicit AOT roots through statically knowable autowire dependencies. */
final readonly class AutowireClassGraph
{
    /** @param array<string,non-empty-string> $aliases */
    public function __construct(
        private array $aliases = [],
        private ?AttributePlanBuilder $plans = null,
    ) {}

    /**
     * @param iterable<AutowireEntry|class-string> $roots
     * @param array<string,true> $excluded
     * @return list<class-string>
     */
    public function expand(iterable $roots, array $excluded = []): array
    {
        $pending = [];

        foreach ($roots as $root) {
            $class = $root instanceof AutowireEntry ? $root->class : $root;
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException(
                    'Autowire entry must contain a non-empty class-string.',
                );
            }

            $resolved = $this->resolveAlias($class);
            if ($resolved !== $class && !self::isLoadable($resolved)) {
                continue;
            }

            $pending[$resolved] = true;
        }

        $result = [];
        while ($pending !== []) {
            $class = array_key_first($pending);
            unset($pending[$class]);

            if (isset($result[$class]) || isset($excluded[$class])) {
                continue;
            }

            if (!self::isLoadable($class)) {
                throw new InvalidConfigurationException(sprintf(
                    'Cannot compile autowire entry "%s": class is not loadable.',
                    $class,
                ));
            }

            /** @var class-string $class */
            $reflection = new ReflectionClass($class);
            if (!is_entry_class_eligible($reflection)
                || self::isBootstrapExtension($reflection)
            ) {
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
        $constructorDisabled = $this->hasConstructorPolicy($class);
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
            try {
                $setup = $attribute->newInstance();
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new InvalidConfigurationException(
                    sprintf('Cannot instantiate #[SetUp] on "%s".', $class->getName()),
                    previous: $e,
                );
            }

            if ($class->hasMethod($setup->method)) {
                $this->appendMethodDependencies($dependencies, $class->getMethod($setup->method));
            }
        }

        return array_keys($dependencies);
    }

    /** @param ReflectionClass<object> $class */
    private function hasConstructorPolicy(ReflectionClass $class): bool
    {
        if ($this->plans !== null) {
            foreach ($this->plans->build($class)->all(ConstructorPolicy::class) as $usage) {
                $definition = $usage->definition;
                if (!$definition->handler instanceof AttributeHandlerInterface) {
                    continue;
                }
                if ($definition->phase === AttributePhase::BeforeInstantiation
                    || $definition->phase === AttributePhase::Both
                ) {
                    return true;
                }
            }

            return false;
        }

        return $class->getAttributes(
            NoConstructor::class,
            ReflectionAttribute::IS_INSTANCEOF,
        ) !== [];
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

    /** @param array<class-string,true> $dependencies */
    private function appendMethodDependencies(array &$dependencies, ReflectionMethod $method): void
    {
        foreach ($method->getParameters() as $parameter) {
            if ($this->isProvidedByAttributeHandler($parameter)) {
                continue;
            }

            $this->appendDependency(
                $dependencies,
                TypeHints::classOf($parameter->getType(), $parameter->getDeclaringClass()),
            );
        }
    }

    private function isProvidedByAttributeHandler(ReflectionParameter $parameter): bool
    {
        if ($this->plans === null) {
            return false;
        }

        foreach ($this->plans->build($parameter)->usages as $usage) {
            if (!$usage->definition->handler instanceof ParameterAttributeHandlerInterface) {
                continue;
            }

            foreach ($usage->definition->capabilities as $capability) {
                if (is_a($capability, ValueProvider::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<class-string,true> $dependencies */
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
        $candidate = new ReflectionClass($dependency);
        if (is_entry_class_eligible($candidate)
            && !self::isBootstrapExtension($candidate)
        ) {
            $dependencies[$candidate->getName()] = true;
        }
    }

    /** @param ReflectionClass<object> $class */
    private static function isBootstrapExtension(ReflectionClass $class): bool
    {
        return $class->implementsInterface(ParameterResolverInterface::class)
            || $class->implementsInterface(AttributeHandlerInterface::class);
    }

    private function resolveAlias(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new InvalidConfigurationException(sprintf(
                    'Cannot compile autowire graph through cyclic alias "%s".',
                    $id,
                ));
            }

            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    private static function isLoadable(string $class): bool
    {
        return class_exists($class)
            || interface_exists($class)
            || trait_exists($class)
            || enum_exists($class);
    }
}
