<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use ReflectionClass;
use ReflectionParameter;

/** Guards mapped HTTP DTO data from shadowing explicitly declared parameter sources. */
final class MappedRequestParameterSourceGuard
{
    /**
     * @phpstan-type SourceBinding array{
     *     parameter: string,
     *     source: class-string,
     *     keys: list<string>
     * }
     *
     * @var array<class-string, list<SourceBinding>>
     */
    private static array $sourceCache = [];

    /**
     * Checks the declared request-mapper type before nested factory resolution.
     *
     * The provenance marker is still propagated because aliases and factory
     * definitions may construct a different concrete class later.
     *
     * @param class-string $dtoClass
     * @param array<string|int, mixed> $data
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
     * Checks the concrete class represented by a resolution id when available.
     *
     * @param array<string|int, mixed> $context
     */
    public static function assertContextNoConflicts(string $class, array $context): void
    {
        self::assertBindingsContextNoConflicts(
            $class,
            self::bindings($class),
            $context,
        );
    }

    /**
     * @param iterable<ParameterTarget> $targets
     * @param array<string|int, mixed> $context
     */
    public static function assertTargetsContextNoConflicts(
        iterable $targets,
        array $context,
    ): void {
        if (MappedRequestContext::get($context) === null) {
            return;
        }

        foreach ($targets as $target) {
            self::assertTargetContextNoConflicts($target, $context);
        }
    }

    /** @param array<string|int, mixed> $context */
    public static function assertTargetContextNoConflicts(
        ParameterTarget $target,
        array $context,
    ): void {
        $provenance = MappedRequestContext::get($context);
        if ($provenance === null) {
            return;
        }

        $binding = self::targetBinding($target);
        if ($binding === null) {
            return;
        }

        foreach ($binding['keys'] as $key) {
            if ($provenance->contains($key)) {
                self::throwConflict(
                    $target->reflection->getDeclaringClass()?->getName()
                        ?? $target->declaringContext,
                    $binding,
                    $key,
                );
            }
        }
    }

    public static function supportsTarget(ParameterTarget $target): bool
    {
        return self::targetBinding($target) !== null;
    }

    /**
     * Runtime helper used by generated ClassDefinition factories. The binding
     * list is emitted at build time, so this path stays reflection-free.
     *
     * @param list<array{parameter: string, source: class-string, keys: list<string>}> $bindings
     * @param array<string|int, mixed> $context
     */
    public static function assertBindingsContextNoConflicts(
        string $class,
        array $bindings,
        array $context,
    ): void {
        $provenance = MappedRequestContext::get($context);
        if ($provenance === null) {
            return;
        }

        foreach ($bindings as $binding) {
            foreach ($binding['keys'] as $key) {
                if ($provenance->contains($key)) {
                    self::throwConflict($class, $binding, $key);
                }
            }
        }
    }

    /**
     * Exposes build-time source bindings for reflection-free generated code.
     *
     * @internal
     * @return list<array{parameter: string, source: class-string, keys: list<string>}>
     */
    public static function bindings(string $class): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return [];
        }

        /** @var class-string $class */
        if (array_key_exists($class, self::$sourceCache)) {
            return self::$sourceCache[$class];
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return self::$sourceCache[$class] = [];
        }

        $bindings = [];

        foreach ($constructor->getParameters() as $parameter) {
            $typeNames = TypeHints::classNames(
                $parameter->getType(),
                $parameter->getDeclaringClass(),
            );
            $source = self::declaredSource($parameter, $typeNames);

            if ($source === null) {
                continue;
            }

            $bindings[] = self::binding(
                $parameter->getName(),
                $source,
                $typeNames,
            );
        }

        return self::$sourceCache[$class] = $bindings;
    }

    /**
     * @return array{parameter: string, source: class-string, keys: list<string>}|null
     */
    private static function targetBinding(ParameterTarget $target): ?array
    {
        $source = self::declaredTargetSource($target);

        return $source === null
            ? null
            : self::binding($target->name, $source, $target->typeNames);
    }

    /**
     * @param class-string $source
     * @param list<class-string> $typeNames
     * @return array{parameter: string, source: class-string, keys: list<string>}
     */
    private static function binding(
        string $parameter,
        string $source,
        array $typeNames,
    ): array {
        return [
            'parameter' => $parameter,
            'source' => $source,
            'keys' => array_values(array_unique([
                $parameter,
                ...$typeNames,
            ])),
        ];
    }

    /**
     * @param list<class-string> $typeNames
     * @return class-string|null
     */
    private static function declaredSource(
        ReflectionParameter $parameter,
        array $typeNames,
    ): ?string {
        foreach ($parameter->getAttributes() as $attribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $attribute->getName();

            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }

        return self::implicitTypeSource($typeNames);
    }

    /** @return class-string|null */
    private static function declaredTargetSource(ParameterTarget $target): ?string
    {
        foreach ($target->attributeClasses as $attributeClass) {
            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }

        return self::implicitTypeSource($target->typeNames);
    }

    /**
     * @param list<class-string> $typeNames
     * @return class-string|null
     */
    private static function implicitTypeSource(array $typeNames): ?string
    {
        foreach ($typeNames as $typeName) {
            if ($typeName === ServerRequestInterface::class
                || $typeName === UriInterface::class
            ) {
                return $typeName;
            }
        }

        return null;
    }

    /**
     * @param array{parameter: string, source: class-string, keys: list<string>} $binding
     */
    private static function throwConflict(
        string $class,
        array $binding,
        string $key,
    ): never {
        throw new RequestParameterSourceConflictException(
            dtoClass: $class,
            key: $key,
            source: $binding['source'],
            parameter: $binding['parameter'],
        );
    }
}
