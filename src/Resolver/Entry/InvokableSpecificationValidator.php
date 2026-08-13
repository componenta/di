<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeInvocation;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\LazyHandler;
use Componenta\DI\Resolver\Attribute\Handler\ProxyHandler;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Validates the class contract required by {@see InvokableResolver}. */
final class InvokableSpecificationValidator
{
    /**
     * Built-in DI attributes whose semantics require the normal attribute
     * lifecycle rather than the raw invokable fast path.
     *
     * Class-level #[Lazy] and #[Proxy] are intentionally excluded because
     * InvokableResolver implements those two creation strategies directly.
     *
     * @var list<class-string>
     */
    private const array LIFECYCLE_ATTRIBUTES = [
        Config::class,
        EntryId::class,
        Env::class,
        Init::class,
        Inject::class,
        Make::class,
        NoConstructor::class,
        SetUp::class,
    ];

    /** @phpstan-assert class-string $class */
    public static function assertValid(
        string $class,
        ?AttributeProcessor $attributeProcessor = null,
    ): void {
        if ($class === '' || !class_exists($class)) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" is not loadable.',
                $class,
            ));
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $hasRequiredConstructorParameters = $constructor !== null
            && $constructor->getNumberOfRequiredParameters() > 0;
        $proxyAttribute = $reflection->getAttributes(Proxy::class)[0] ?? null;

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isVariadic() && !$parameter->isPassedByReference()) {
                    continue;
                }

                throw new InvalidConfigurationException(sprintf(
                    'Invokable class "%s" cannot use variadic or by-reference constructor parameters.',
                    $class,
                ));
            }
        }

        if ($proxyAttribute !== null) {
            $proxy = $proxyAttribute->newInstance();

            if (!$proxy instanceof Proxy || $proxy->class !== null) {
                throw new InvalidConfigurationException(
                    'Class-level #[Proxy] must not specify a proxy class; the marked class is used.',
                );
            }
        }

        $isLazy = $proxyAttribute === null
            && $reflection->getAttributes(Lazy::class) !== [];
        $isStructurallyConcrete = !$reflection->isAnonymous()
            && !$reflection->isInterface()
            && !$reflection->isTrait()
            && !$reflection->isAbstract()
            && !$reflection->isEnum();

        // Native lazy ghosts can initialize a private no-argument constructor
        // through reflection. Eager entries and virtual proxies need an
        // ordinarily instantiable class because their backing object is built
        // with `new $class()`. Optional constructor parameters are allowed on
        // this explicit fast path and use their PHP defaults; required
        // dependencies belong on the normal reflection/compiled DI path.
        if (!$isStructurallyConcrete
            || $hasRequiredConstructorParameters
            || (!$isLazy && !$reflection->isInstantiable())
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" must be concrete and require no constructor arguments.',
                $class,
            ));
        }

        self::assertBuiltinLifecycleCompatible($reflection);

        if ($attributeProcessor !== null) {
            self::assertAttributePipelineCompatible($reflection, $attributeProcessor);
        }
    }

    /**
     * Returns whether the active attribute pipeline can be skipped safely by
     * the raw invokable path.
     *
     * This checks the actual selected handlers, not just attribute names. A
     * custom handler that claims #[Lazy], #[Proxy] or any custom attribute is
     * therefore not silently bypassed by the optimization.
     *
     * @param ReflectionClass<object> $reflection
     */
    public static function supportsAttributePipeline(
        ReflectionClass $reflection,
        AttributeProcessor $attributeProcessor,
    ): bool {
        $invocations = $attributeProcessor->invocations($reflection);

        foreach ([...$invocations['before'], ...$invocations['after']] as $invocation) {
            if (!self::isNativeInvokableInvocation($invocation)) {
                return false;
            }
        }

        return true;
    }

    /** @param ReflectionClass<object> $reflection */
    private static function assertAttributePipelineCompatible(
        ReflectionClass $reflection,
        AttributeProcessor $attributeProcessor,
    ): void {
        $invocations = $attributeProcessor->invocations($reflection);

        foreach ([...$invocations['before'], ...$invocations['after']] as $invocation) {
            if (self::isNativeInvokableInvocation($invocation)) {
                continue;
            }

            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" cannot bypass attribute #[%s] handled by "%s": '
                . 'the attribute requires the normal DI attribute lifecycle.',
                $reflection->getName(),
                $invocation->attributeClass,
                $invocation->handler::class,
            ));
        }
    }

    private static function isNativeInvokableInvocation(AttributeInvocation $invocation): bool
    {
        if (!$invocation->target instanceof ReflectionClass) {
            return false;
        }

        if (is_a($invocation->attributeClass, Lazy::class, true)) {
            return $invocation->handler instanceof LazyHandler;
        }

        if (!is_a($invocation->attributeClass, Proxy::class, true)
            || !$invocation->handler instanceof ProxyHandler
        ) {
            return false;
        }

        $proxy = $invocation->newAttribute();

        return $proxy instanceof Proxy && $proxy->class === null;
    }

    /** @param ReflectionClass<object> $reflection */
    private static function assertBuiltinLifecycleCompatible(ReflectionClass $reflection): void
    {
        self::assertTargetCompatible($reflection, $reflection->getName());

        foreach ($reflection->getProperties() as $property) {
            self::assertTargetCompatible(
                $property,
                sprintf('%s::$%s', $property->getDeclaringClass()->getName(), $property->getName()),
            );
        }

        foreach ($reflection->getMethods() as $method) {
            self::assertTargetCompatible(
                $method,
                sprintf('%s::%s()', $method->getDeclaringClass()->getName(), $method->getName()),
            );
        }

        // ReflectionClass omits private members declared by ancestors. The
        // normal AttributeProcessor explicitly includes them, so invokable
        // validation must inspect the same surface to avoid a semantic gap.
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
                if ($property->getDeclaringClass()->getName() === $parent->getName()) {
                    self::assertTargetCompatible(
                        $property,
                        sprintf('%s::$%s', $parent->getName(), $property->getName()),
                    );
                }
            }

            foreach ($parent->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
                if ($method->getDeclaringClass()->getName() === $parent->getName()) {
                    self::assertTargetCompatible(
                        $method,
                        sprintf('%s::%s()', $parent->getName(), $method->getName()),
                    );
                }
            }
        }
    }

    /**
     * @param ReflectionClass<object>|ReflectionProperty|ReflectionMethod $target
     */
    private static function assertTargetCompatible(
        ReflectionClass|ReflectionProperty|ReflectionMethod $target,
        string $targetName,
    ): void {
        foreach ($target->getAttributes() as $attribute) {
            $attributeClass = $attribute->getName();

            if ($target instanceof ReflectionClass
                && (is_a($attributeClass, Lazy::class, true)
                    || is_a($attributeClass, Proxy::class, true))
            ) {
                continue;
            }

            if (!self::requiresLifecycle($attributeClass)) {
                continue;
            }

            $className = $target instanceof ReflectionClass
                ? $target->getName()
                : $target->getDeclaringClass()->getName();

            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" cannot use #[%s] on "%s": '
                . 'the attribute requires the normal DI attribute lifecycle.',
                $className,
                $attributeClass,
                $targetName,
            ));
        }
    }

    private static function requiresLifecycle(string $attributeClass): bool
    {
        if (is_a($attributeClass, Proxy::class, true)) {
            return true;
        }

        foreach (self::LIFECYCLE_ATTRIBUTES as $lifecycleAttribute) {
            if (is_a($attributeClass, $lifecycleAttribute, true)) {
                return true;
            }
        }

        return false;
    }

    private function __construct() {}
}
