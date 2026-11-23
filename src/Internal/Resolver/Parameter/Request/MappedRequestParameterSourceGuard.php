<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Closure;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetAccess;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionParameter;

use function Componenta\DI\Internal\is_attribute_class_loaded;

/**
 * Guards mapped HTTP DTO data from shadowing explicitly declared parameter sources.
 *
 * @internal
 */
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
     */
    public static function assertClassNoConflicts(string $class, ?MappedRequestContext $provenance): void
    {
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
     * Selects potential sources without retrying autoloaders. An optional
     * provenance snapshot narrows checks to keys fixed for one invocation.
     * Loaded non-source classes cannot change their ancestry. Keep unresolved
     * declarations whenever their keys can conflict, even if loaded later.
     *
     * @internal
     * @param iterable<ParameterTarget> $targets
     * @return list<ParameterTarget>
     */
    public static function potentialSourceTargets(iterable $targets, ?MappedRequestContext $provenance = null): array
    {
        $potential = [];
        foreach ($targets as $target) {
            if ($provenance !== null) {
                $matches = $provenance->contains($target->name);
                foreach ($target->typeNames as $typeName) {
                    $matches = $matches || $provenance->contains($typeName);
                }
                if (!$matches) {
                    continue;
                }
            }
            $complete = true;
            $source = self::declaredTargetSource($target, $complete, autoload: false);
            if ($source !== null || !$complete) {
                $potential[] = $target;
            }
        }
        return $potential;
    }

    /**
     * @internal
     * @param iterable<ParameterTarget> $targets
     * @param class-string|null $diagnosticOwner
     */
    public static function assertTargetsProvenanceNoConflicts(
        iterable $targets,
        ?MappedRequestContext $provenance,
        ?string $diagnosticOwner = null,
        bool $autoload = true,
    ): void {
        if ($provenance === null) {
            return;
        }
        foreach ($autoload ? [true, false] : [false] as $discover) {
            $pending = [];
            foreach ($targets as $target) {
                $complete = true;
                $binding = self::targetBinding($target, $complete, $discover);
                if (!$complete) {
                    $pending[] = $target;
                }
                if ($binding === null) {
                    continue;
                }
                foreach ($binding['keys'] as $key) {
                    if ($provenance->contains($key)) {
                        // Diagnostic metadata may execute custom reflector code.
                        // Do not run it for a target with no conflicting key.
                        $class = $diagnosticOwner ?? ParameterTargetAccess::declaration($target)->metadataTarget()->getDeclaringClass()?->getName();
                        if ($class === null) {
                            break;
                        }
                        self::throwConflict($class, $binding, $key);
                    }
                }
            }
            // Later discovery may reveal an earlier skipped source. Only keep
            // incomplete targets, and never rewind a caller's iterable or retry loaders.
            $targets = $pending;
        }
    }

    public static function supportsTarget(ParameterTarget $target): bool
    {
        $complete = true;
        return self::targetBinding($target, $complete, true) !== null
            || (!$complete && self::targetBinding($target, $complete, false) !== null);
    }

    /**
     * Retains only unresolved declarations whose parameter keys were mapped.
     *
     * @param class-string $owner
     * @return (Closure():void)|null
     */
    public static function watchInvocation(
        ReflectionFunctionAbstract $callable,
        MappedRequestContext $provenance,
        string $owner,
        bool $skipFirst = false,
    ): ?Closure {
        $checks = [];
        foreach ($callable->getParameters() as $parameter) {
            if (($skipFirst && $parameter->getPosition() === 0) || ($attributes = $parameter->getAttributes()) === []) {
                continue;
            }
            $name = $parameter->getName();
            $keys = self::bindingKeys($name, TypeHints::classNames($parameter->getType(), $parameter->getDeclaringClass()));
            $key = array_find($keys, $provenance->contains(...));
            if ($key === null) {
                continue;
            }

            $pending = [];
            foreach ($attributes as $attribute) {
                $attributeClass = $attribute->getName();
                if (!is_attribute_class_loaded($attributeClass)) {
                    $pending[] = $attributeClass;
                }
            }
            if ($pending !== []) {
                $checks[] = [$name, $key, $pending];
            }
        }
        if ($checks === []) {
            return null;
        }

        // The completion check must let callback captures and reflectors retire.
        return static function () use ($checks, $owner): void {
            foreach ($checks as [$name, $key, $pending]) {
                foreach ($pending as $attribute) {
                    if (is_attribute_class_loaded($attribute) && is_a($attribute, ParameterSourceAttributeInterface::class, true)) {
                        throw new RequestParameterSourceConflictException($owner, $key, $attribute, $name);
                    }
                }
            }
        };
    }

    /**
     * @param class-string $class
     * @return list<array{parameter:string,source:class-string,keys:list<string>}>
     */
    public static function bindings(string $class): array
    {
        if (!class_exists($class) && !interface_exists($class) && !class_exists($class, false)) {
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

        foreach ([true, false] as $autoload) {
            /** @var list<array{parameter:string,source:class-string,keys:list<string>}> $bindings */
            $bindings = [];
            $complete = true;
            foreach ($constructor->getParameters() as $parameter) {
                /** @var list<class-string> $typeNames */
                $typeNames = TypeHints::classNames($parameter->getType(), $parameter->getDeclaringClass());
                $source = self::declaredSource($parameter, $complete, $autoload);
                if ($source !== null) {
                    $bindings[] = self::binding($parameter->getName(), $source, $typeNames);
                }
            }
            if ($complete) {
                self::$sourceCache[$class] = $bindings;
                break;
            }
            // A later attribute can load an earlier skipped source, including
            // on another parameter. Reinspect the loaded declarations without
            // retrying unavailable names or executing another autoloader.
        }

        return $bindings;
    }

    /** @return array{parameter:string,source:class-string,keys:list<string>}|null */
    private static function targetBinding(ParameterTarget $target, bool &$complete, bool $autoload): ?array
    {
        $source = self::declaredTargetSource($target, $complete, $autoload);
        return $source === null ? null : self::binding($target->name, $source, $target->typeNames);
    }

    /**
     * @param class-string $source
     * @param list<class-string> $typeNames
     * @return array{parameter:string,source:class-string,keys:list<string>}
     */
    private static function binding(string $parameter, string $source, array $typeNames): array
    {
        return [
            'parameter' => $parameter,
            'source' => $source,
            'keys' => self::bindingKeys($parameter, $typeNames),
        ];
    }

    /**
     * @param list<class-string> $typeNames
     * @return list<string>
     */
    private static function bindingKeys(string $parameter, array $typeNames): array
    {
        /** @var list<string> $keys */
        $keys = [$parameter];
        foreach ($typeNames as $typeName) {
            if (!in_array($typeName, $keys, true)) {
                $keys[] = $typeName;
            }
        }

        return $keys;
    }

    /** @return class-string|null */
    private static function declaredSource(ReflectionParameter $parameter, bool &$complete, bool $autoload): ?string
    {
        foreach ($parameter->getAttributes() as $attribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $attribute->getName();
            if (!class_exists($attributeClass, $autoload) && !interface_exists($attributeClass, $autoload) && !class_exists($attributeClass, false)) {
                $complete = false;
                continue;
            }
            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }
        return null;
    }

    /** @return class-string|null */
    private static function declaredTargetSource(ParameterTarget $target, bool &$complete, bool $autoload): ?string
    {
        foreach ($target->attributeClasses as $attributeClass) {
            if (!$autoload && !is_attribute_class_loaded($attributeClass)) {
                $complete = false;
                continue;
            }
            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
            if (!is_attribute_class_loaded($attributeClass)) {
                $complete = false;
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
