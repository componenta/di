<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;
use ReflectionClass;
use ReflectionParameter;

/** Guards mapped HTTP DTO data from shadowing explicitly declared parameter sources. @internal */
final class MappedRequestParameterSourceGuard
{
    /** @var array<class-string,list<array{parameter:string,source:class-string,keys:list<string>}>> */
    private static array $sourceCache = [];

    /**
     * @param class-string $dtoClass
     * @param array<string|int,mixed> $data
     */
    public static function assertNoConflicts(string $dtoClass, array $data): void
    {
        if ($data === []) {
            return;
        }
        foreach (self::bindings($dtoClass) as $binding) {
            foreach ($binding['keys'] as $key) {
                if (array_key_exists($key, $data)) {
                    self::throwConflict($dtoClass, $binding, $key);
                }
            }
        }
    }

    /**
     * @param class-string $class
     * @param array<string|int,mixed> $context
     */
    public static function assertClassContextNoConflicts(string $class, array $context): void
    {
        $provenance = MappedRequestContext::get($context);
        if ($provenance === null) {
            return;
        }
        foreach (self::bindings($class) as $binding) {
            foreach ($binding['keys'] as $key) {
                if ($provenance->contains($key)) {
                    self::throwConflict($class, $binding, $key);
                }
            }
        }
    }

    /**
     * @param iterable<ParameterTarget> $targets
     * @param array<string|int,mixed> $context
     */
    public static function assertTargetsContextNoConflicts(iterable $targets, array $context): void
    {
        $provenance = MappedRequestContext::get($context);
        if ($provenance === null) {
            return;
        }
        foreach ($targets as $target) {
            self::assertTargetProvenanceNoConflicts($target, $provenance);
        }
    }

    public static function assertTargetProvenanceNoConflicts(
        ParameterTarget $target,
        MappedRequestContext $provenance,
    ): void {
        $binding = self::targetBinding($target);
        if ($binding === null) {
            return;
        }
        $declaringClass = $target->reflection->getDeclaringClass();
        if ($declaringClass === null) {
            return;
        }
        /** @var class-string $class */
        $class = $declaringClass->getName();
        foreach ($binding['keys'] as $key) {
            if ($provenance->contains($key)) {
                self::throwConflict($class, $binding, $key);
            }
        }
    }

    public static function supportsTarget(ParameterTarget $target): bool
    {
        return self::targetBinding($target) !== null;
    }

    /**
     * @param class-string $class
     * @return list<array{parameter:string,source:class-string,keys:list<string>}>
     */
    public static function bindings(string $class): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return [];
        }
        if (array_key_exists($class, self::$sourceCache)) {
            return self::$sourceCache[$class];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return self::$sourceCache[$class] = [];
        }

        /** @var list<array{parameter:string,source:class-string,keys:list<string>}> $bindings */
        $bindings = [];
        foreach ($constructor->getParameters() as $parameter) {
            /** @var list<class-string> $typeNames */
            $typeNames = TypeHints::classNames($parameter->getType(), $parameter->getDeclaringClass());
            $source = self::declaredSource($parameter);
            if ($source !== null) {
                $bindings[] = self::binding($parameter->getName(), $source, $typeNames);
            }
        }
        return self::$sourceCache[$class] = $bindings;
    }

    /** @return array{parameter:string,source:class-string,keys:list<string>}|null */
    private static function targetBinding(ParameterTarget $target): ?array
    {
        $source = self::declaredTargetSource($target);
        return $source === null ? null : self::binding($target->name, $source, $target->typeNames);
    }

    /**
     * @param class-string $source
     * @param list<class-string> $typeNames
     * @return array{parameter:string,source:class-string,keys:list<string>}
     */
    private static function binding(string $parameter, string $source, array $typeNames): array
    {
        /** @var list<string> $keys */
        $keys = [$parameter];
        foreach ($typeNames as $typeName) {
            if (!in_array($typeName, $keys, true)) {
                $keys[] = $typeName;
            }
        }

        return [
            'parameter' => $parameter,
            'source' => $source,
            'keys' => $keys,
        ];
    }

    /** @return class-string|null */
    private static function declaredSource(ReflectionParameter $parameter): ?string
    {
        foreach ($parameter->getAttributes() as $attribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $attribute->getName();
            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }
        return null;
    }

    /** @return class-string|null */
    private static function declaredTargetSource(ParameterTarget $target): ?string
    {
        foreach ($target->attributeClasses as $attributeClass) {
            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }
        return null;
    }

    /**
     * @param class-string $class
     * @param array{parameter:string,source:class-string,keys:list<string>} $binding
     */
    private static function throwConflict(string $class, array $binding, string $key): never
    {
        throw new RequestParameterSourceConflictException(
            dtoClass: $class,
            key: $key,
            source: $binding['source'],
            parameter: $binding['parameter'],
        );
    }
}
