<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Entry;

use BackedEnum;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;
use LogicException;
use UnitEnum;

/** Stable signatures used to reject a generated resolver built for another runtime. */
final class GeneratedEntryResolverFingerprint
{
    /**
     * Stable runtime type descriptors. Anonymous-class deployment paths are
     * deliberately excluded.
     *
     * @param list<object> $objects
     * @return list<string>
     */
    public static function objectTypes(array $objects): array
    {
        return array_map(
            static fn(object $object): string => self::classIdentity(
                new ReflectionObject($object),
            ),
            array_values($objects),
        );
    }

    /**
     * Named implementation classes whose source files can be re-reflected in a
     * later process. Anonymous implementations are covered by objectTypes() and
     * objects() instead.
     *
     * @param list<object> $objects
     * @return list<class-string>
     */
    public static function objectSourceClasses(array $objects): array
    {
        $classes = [];

        foreach ($objects as $object) {
            self::collectObjectSourceClasses(new ReflectionObject($object), $classes);
        }

        return array_keys($classes);
    }

    /** @param array<class-string, true> $classes */
    private static function collectObjectSourceClasses(
        ReflectionClass $class,
        array &$classes,
    ): void {
        if (!$class->isAnonymous()) {
            $classes[$class->getName()] = true;
            return;
        }

        $parent = $class->getParentClass();
        if ($parent !== false) {
            self::collectObjectSourceClasses($parent, $classes);
        }

        foreach ($class->getInterfaces() as $interface) {
            self::collectObjectSourceClasses($interface, $classes);
        }

        foreach ($class->getTraits() as $trait) {
            self::collectObjectSourceClasses($trait, $classes);
        }
    }

    /**
     * Fingerprints ordered extension instances without traversing dependency
     * object graphs. Direct scalar/array/enum configuration is included;
     * object collaborators contribute their stable type identity.
     *
     * @param list<object> $objects
     */
    public static function objects(array $objects): string
    {
        $descriptors = [];

        foreach (array_values($objects) as $index => $object) {
            $reflection = new ReflectionObject($object);
            $descriptors[] = [
                'index' => $index,
                'class' => self::classIdentity($reflection),
                'state' => self::rootObjectState($object, $reflection),
            ];
        }

        return hash('sha256', serialize($descriptors));
    }

    /**
     * Fingerprints the source files that define every named class, parent,
     * interface and recursively used trait. Absolute deployment paths are not
     * included, so the same artifact can move together with unchanged code.
     *
     * @param list<class-string> $classes
     */
    public static function sources(array $classes): string
    {
        $sources = [];

        foreach ($classes as $class) {
            if (!class_exists($class)
                && !interface_exists($class)
                && !trait_exists($class)
                && !enum_exists($class)
            ) {
                $sources[$class] = 'missing';
                continue;
            }

            self::collect(new ReflectionClass($class), $sources);
        }

        ksort($sources);

        return hash('sha256', serialize($sources));
    }

    /** @return array<string, mixed> */
    private static function rootObjectState(
        object $object,
        ?ReflectionObject $reflection = null,
    ): array {
        $reflection ??= new ReflectionObject($object);
        $state = [];
        $properties = $reflection->getProperties();

        // ReflectionObject omits private properties declared by ancestors.
        // Add every ancestor's own properties and deduplicate by declaration.
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $parent->getName()) {
                    $properties[] = $property;
                }
            }
        }

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $key = self::propertyKey($property);
            if (array_key_exists($key, $state)) {
                continue;
            }

            if ($property->isVirtual()) {
                $state[$key] = ['virtual' => true];
                continue;
            }

            $state[$key] = $property->isInitialized($object)
                ? self::normalize($property->getRawValue($object))
                : ['uninitialized' => true];
        }

        ksort($state);

        return $state;
    }

    private static function propertyKey(ReflectionProperty $property): string
    {
        return self::classIdentity($property->getDeclaringClass())
            . '::$'
            . $property->getName();
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 32) {
            return ['depth-limit' => true];
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item, $depth + 1);
            }

            return $normalized;
        }

        if ($value instanceof UnitEnum) {
            return [
                'enum' => $value::class,
                'case' => $value->name,
                'value' => $value instanceof BackedEnum ? $value->value : null,
            ];
        }

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            $scope = $reflection->getClosureScopeClass();
            $bound = $reflection->getClosureThis();

            return [
                'closure' => [
                    'source' => self::sourceSegmentFingerprint($reflection),
                    'scope' => $scope === null ? null : self::classIdentity($scope),
                    'bound' => $bound === null
                        ? null
                        : self::classIdentity(new ReflectionObject($bound)),
                    'static' => self::normalize(
                        $reflection->getStaticVariables(),
                        $depth + 1,
                    ),
                ],
            ];
        }

        if (is_object($value)) {
            return [
                'class' => self::classIdentity(new ReflectionObject($value)),
            ];
        }

        if (is_resource($value)) {
            return ['resource' => get_resource_type($value)];
        }

        return ['type' => get_debug_type($value)];
    }

    private static function classIdentity(ReflectionClass $class): string
    {
        if (!$class->isAnonymous()) {
            return $class->getName();
        }

        $interfaces = array_keys($class->getInterfaces());
        $traits = array_keys($class->getTraits());
        sort($interfaces, SORT_STRING);
        sort($traits, SORT_STRING);

        return 'anonymous:' . hash('sha256', serialize([
            'source' => self::sourceSegmentFingerprint($class),
            'parent' => ($parent = $class->getParentClass()) === false
                ? null
                : self::classIdentity($parent),
            'interfaces' => $interfaces,
            'traits' => $traits,
        ]));
    }

    private static function sourceSegmentFingerprint(
        ReflectionClass|ReflectionFunction $reflection,
    ): string {
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if (!is_string($file) || !is_file($file)) {
            if ($reflection->isUserDefined()) {
                throw new LogicException(sprintf(
                    'Cannot fingerprint user-defined %s without a readable source file.',
                    $reflection instanceof ReflectionClass
                        ? sprintf('class "%s"', $reflection->getName())
                        : 'closure',
                ));
            }

            return hash('sha256', serialize([
                'internal' => true,
                'start' => $start,
                'end' => $end,
            ]));
        }

        $lines = file($file);
        if ($lines === false) {
            throw new LogicException(sprintf(
                'Cannot read source file for user-defined %s.',
                $reflection instanceof ReflectionClass
                    ? sprintf('class "%s"', $reflection->getName())
                    : 'closure',
            ));
        }

        $source = implode('', array_slice(
            $lines,
            max(0, $start - 1),
            max(0, $end - $start + 1),
        ));

        return hash('sha256', $source);
    }

    /** @param array<class-string, string> $sources */
    private static function collect(ReflectionClass $class, array &$sources): void
    {
        $name = $class->getName();

        if (isset($sources[$name])) {
            return;
        }

        $file = $class->getFileName();

        if ((!is_string($file) || !is_file($file)) && $class->isUserDefined()) {
            throw new LogicException(sprintf(
                'Cannot fingerprint user-defined class "%s" without a readable source file.',
                $name,
            ));
        }

        if (is_string($file) && is_file($file)) {
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                throw new LogicException(sprintf(
                    'Cannot hash source file for user-defined class "%s".',
                    $name,
                ));
            }

            $sources[$name] = $hash;
        } else {
            $sources[$name] = 'internal';
        }

        $parent = $class->getParentClass();
        if ($parent !== false) {
            self::collect($parent, $sources);
        }

        foreach ($class->getInterfaces() as $interface) {
            self::collect($interface, $sources);
        }

        foreach ($class->getTraits() as $trait) {
            self::collect($trait, $sources);
        }
    }
}
