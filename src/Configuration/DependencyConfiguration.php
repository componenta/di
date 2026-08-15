<?php

declare(strict_types=1);

namespace Componenta\DI\Configuration;

use Closure;
use Componenta\DI\AliasResolver;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;

/**
 * Normalizes and validates the declarative dependency configuration consumed by
 * ContainerBuilder. Runtime container assembly intentionally remains in the
 * builder; this class owns only configuration-shape concerns and normalization
 * of equivalent resolver configuration forms.
 *
 * @internal
 *
 * @phpstan-type CallableReference array{0: object|non-empty-string, 1: non-empty-string}
 * @phpstan-type DelegatorSpecification callable|non-empty-string|CallableReference
 * @phpstan-type DependencyShape array{
 *     factories?: array<string, mixed>,
 *     invokables?: array<int|string, class-string>,
 *     aliases?: array<string, non-empty-string>,
 *     delegators?: array<string, mixed>,
 *     services?: array<string, mixed>,
 *     parameter_resolvers?: array<int, mixed>,
 *     parameter_resolvers_replace?: bool,
 *     attribute_handlers?: list<mixed>,
 *     attribute_handlers_replace?: bool
 * }
 */
final class DependencyConfiguration
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $dependencies
     * @param array<string, non-empty-string> $defaultAliases
     * @return array<string, mixed>
     */
    public static function normalize(array $dependencies, array $defaultAliases = []): array
    {
        self::assertShape($dependencies);

        $aliases = array_merge(
            $defaultAliases,
            $dependencies[ConfigKey::ALIASES] ?? [],
        );
        $invokables = [];

        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $key => $value) {
            if (!in_array($value, $invokables, true)) {
                $invokables[] = $value;
            }

            if (is_string($key)) {
                self::assertInvokableAliasCompatible($aliases, $key, $value);
                $aliases[$key] ??= $value;
            }
        }

        $delegators = [];
        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $list) {
            $delegators[$id] = self::normalizeDelegatorList($list, $id);
        }

        return array_filter(
            [
                ConfigKey::FACTORIES => $dependencies[ConfigKey::FACTORIES] ?? [],
                ConfigKey::INVOKABLES => $invokables,
                ConfigKey::ALIASES => $aliases,
                ConfigKey::DELEGATORS => $delegators,
                ConfigKey::SERVICES => $dependencies[ConfigKey::SERVICES] ?? [],
                ConfigKey::PARAMETER_RESOLVERS
                    => $dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [],
                ConfigKey::PARAMETER_RESOLVERS_REPLACE
                    => $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false,
                ConfigKey::ATTRIBUTE_HANDLERS
                    => $dependencies[ConfigKey::ATTRIBUTE_HANDLERS] ?? [],
                ConfigKey::ATTRIBUTE_HANDLERS_REPLACE
                    => $dependencies[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE] ?? false,
            ],
            static fn(mixed $value): bool => $value !== [] && $value !== false,
        );
    }

    /**
     * Extracts dependencies from the versioned persistent-cache envelope.
     * Compiled definitions are normalized to their encoded representation at
     * this boundary so an object reconstructed by a PHP cache exporter cannot
     * acquire the programmatic-object path semantics used outside
     * configureFromCache().
     *
     * @param array<string, mixed> $cache
     * @return array<string, mixed>
     */
    public static function dependenciesFromCache(
        array $cache,
        int $expectedVersion,
    ): array {
        self::assertCacheEnvelopeShape($cache);

        $version = $cache['version'];
        if ($version !== $expectedVersion) {
            throw new InvalidConfigurationException(sprintf(
                'Unsupported container cache version "%s"; expected "%d".',
                is_scalar($version) ? (string) $version : get_debug_type($version),
                $expectedVersion,
            ));
        }

        $dependencies = $cache[ConfigKey::DEPENDENCIES] ?? [];
        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException(
                'Container cache dependencies section must be an array.',
            );
        }

        /** @var array<string, mixed> $dependencies */
        return self::encodeCompiledFactoryDefinitions($dependencies);
    }

    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private static function encodeCompiledFactoryDefinitions(array $dependencies): array
    {
        $factories = $dependencies[ConfigKey::FACTORIES] ?? null;
        if (!is_array($factories)) {
            return $dependencies;
        }

        foreach ($factories as $id => $factory) {
            if ($factory instanceof CompiledFactoryDefinition) {
                $factories[$id] = $factory->encode();
            }
        }

        $dependencies[ConfigKey::FACTORIES] = $factories;

        return $dependencies;
    }

    /**
     * Validates dependency shape and normalizes definition objects that are
     * equivalent to an existing section shorthand. The input array is local to
     * builder/config normalization, so callers observe one canonical resolver
     * configuration after this boundary.
     *
     * Unknown DefinitionInterface implementations are valid declarative input
     * at this boundary so a configured definition compiler can translate them
     * before the persistent cache is written. Runtime resolver assembly still
     * rejects definitions it does not support.
     *
     * @param array<string, mixed> $dependencies
     * @phpstan-assert DependencyShape $dependencies
     */
    public static function assertShape(array &$dependencies): void
    {
        $allowed = array_fill_keys(ConfigKey::dependencyKeys(), true);

        foreach ($dependencies as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container dependency key "%s".',
                    (string) $key,
                ));
            }
        }

        foreach ([
            ConfigKey::FACTORIES,
            ConfigKey::INVOKABLES,
            ConfigKey::ALIASES,
            ConfigKey::DELEGATORS,
            ConfigKey::SERVICES,
            ConfigKey::PARAMETER_RESOLVERS,
            ConfigKey::ATTRIBUTE_HANDLERS,
        ] as $key) {
            if (array_key_exists($key, $dependencies)
                && !is_array($dependencies[$key])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be an array; got %s.',
                    $key,
                    get_debug_type($dependencies[$key]),
                ));
            }
        }

        foreach ([
            ConfigKey::PARAMETER_RESOLVERS_REPLACE,
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE,
        ] as $key) {
            if (array_key_exists($key, $dependencies)
                && !is_bool($dependencies[$key])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be bool; got %s.',
                    $key,
                    get_debug_type($dependencies[$key]),
                ));
            }
        }

        $parameterResolvers = $dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [];
        if (!is_array($parameterResolvers)) {
            throw new InvalidConfigurationException('Parameter resolvers must be an array.');
        }

        foreach ($parameterResolvers as $priority => $resolver) {
            if (!is_int($priority)) {
                throw new InvalidConfigurationException(sprintf(
                    'Parameter resolver priority must be int; got %s.',
                    get_debug_type($priority),
                ));
            }

            self::assertExtensionSpecification($resolver, 'parameter resolver');
        }

        $handlers = $dependencies[ConfigKey::ATTRIBUTE_HANDLERS] ?? [];
        if (!is_array($handlers) || ($handlers !== [] && !array_is_list($handlers))) {
            throw new InvalidConfigurationException(
                'Attribute handlers must be configured as a list in registration order.',
            );
        }

        foreach ($handlers as $handler) {
            self::assertExtensionSpecification($handler, 'attribute handler');
        }

        $invokables = $dependencies[ConfigKey::INVOKABLES] ?? [];
        if (!is_array($invokables)) {
            throw new InvalidConfigurationException('Invokables must be an array.');
        }

        foreach ($invokables as $key => $class) {
            if ($class instanceof InvokableDefinition) {
                $class = $class->value;
                $invokables[$key] = $class;
            }

            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException(sprintf(
                    'Invokable entry must be a non-empty class-string or InvokableDefinition; got %s.',
                    get_debug_type($class),
                ));
            }
        }

        if (array_key_exists(ConfigKey::INVOKABLES, $dependencies)) {
            $dependencies[ConfigKey::INVOKABLES] = $invokables;
        }

        $aliases = $dependencies[ConfigKey::ALIASES] ?? [];
        if (!is_array($aliases)) {
            throw new InvalidConfigurationException('Aliases must be an array.');
        }

        foreach ($aliases as $alias => $target) {
            if ($target instanceof InvokableDefinition) {
                $target = $target->value;
                $aliases[$alias] = $target;
            }

            if (!is_string($alias) || $alias === ''
                || !is_string($target) || $target === ''
            ) {
                throw new InvalidConfigurationException(
                    'Aliases must map non-empty string ids to non-empty string targets.',
                );
            }
        }

        if (array_key_exists(ConfigKey::ALIASES, $dependencies)) {
            $dependencies[ConfigKey::ALIASES] = $aliases;
        }

        foreach ([
            ConfigKey::FACTORIES,
            ConfigKey::DELEGATORS,
            ConfigKey::SERVICES,
        ] as $key) {
            $section = $dependencies[$key] ?? [];
            if (!is_array($section)) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be an array.',
                    $key,
                ));
            }

            foreach ($section as $id => $value) {
                if (!is_string($id) || $id === '') {
                    throw new InvalidConfigurationException(sprintf(
                        'Container dependency "%s" requires non-empty string ids.',
                        $key,
                    ));
                }

                if ($key === ConfigKey::FACTORIES) {
                    if ($value instanceof FactoryDefinition) {
                        $section[$id] = $value->value;
                        continue;
                    }

                    if (!$value instanceof DefinitionInterface
                        || $value instanceof ClassDefinition
                        || $value instanceof CompiledFactoryDefinition
                    ) {
                        FactorySpecificationValidator::assertValid($id, $value);
                    }
                } elseif ($key === ConfigKey::DELEGATORS) {
                    self::normalizeDelegatorList($value, $id);
                }
            }

            if ($key === ConfigKey::FACTORIES && array_key_exists($key, $dependencies)) {
                $dependencies[$key] = $section;
            }
        }
    }

    /** @return list<DelegatorSpecification> */
    public static function normalizeDelegatorList(mixed $value, string $id): array
    {
        $items = self::isCallableArraySpecification($value)
            ? [$value]
            : (is_array($value) && array_is_list($value) ? $value : [$value]);
        $normalized = [];

        foreach ($items as $delegator) {
            $normalized[] = self::normalizeDelegatorSpecification($delegator, $id);
        }

        return $normalized;
    }

    /** @return DelegatorSpecification */
    public static function normalizeDelegatorSpecification(mixed $delegator, string $id): mixed
    {
        if (is_callable($delegator)) {
            return $delegator;
        }

        if (is_string($delegator) && $delegator !== '') {
            return $delegator;
        }

        if (self::isCallableArraySpecification($delegator)
            || self::isDeferredCallableArraySpecification($delegator)
        ) {
            /** @var CallableReference $delegator */
            return $delegator;
        }

        throw new InvalidConfigurationException(sprintf(
            'Delegator for "%s" must be callable, non-empty string or [class|object, method]; got %s.',
            $id,
            get_debug_type($delegator),
        ));
    }

    public static function assertExtensionSpecification(mixed $extension, string $kind): void
    {
        if ($extension instanceof ParameterResolverInterface
            || $extension instanceof AttributeHandlerInterface
            || $extension instanceof Closure
            || is_callable($extension)
            || (is_string($extension) && $extension !== '')
            || self::isDeferredCallableArraySpecification($extension)
        ) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            '%s specification must be an instance, callable, non-empty service id or [service-id, method]; got %s.',
            ucfirst($kind),
            get_debug_type($extension),
        ));
    }

    /** @param array<string, non-empty-string> $aliases */
    public static function assertInvokableAliasCompatible(
        array $aliases,
        string $alias,
        string $target,
    ): void {
        if (!array_key_exists($alias, $aliases)) {
            return;
        }

        $resolver = new AliasResolver($aliases);
        $existingTarget = $resolver->resolve($alias);
        $requestedTarget = $resolver->resolve($target);

        if ($existingTarget === $requestedTarget) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Invokable alias "%s" conflicts with existing target "%s"; requested "%s".',
            $alias,
            $existingTarget,
            $requestedTarget,
        ));
    }

    /** @param array<string, mixed> $cache */
    private static function assertCacheEnvelopeShape(array $cache): void
    {
        $allowed = [
            'version' => true,
            ConfigKey::DEPENDENCIES => true,
            ContainerBuilder::CACHE_VALIDATED_KEY => true,
        ];

        foreach ($cache as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container cache key "%s".',
                    (string) $key,
                ));
            }
        }

        if (!array_key_exists('version', $cache)) {
            throw new InvalidConfigurationException(
                'Container cache envelope must declare a version.',
            );
        }

        if (array_key_exists(ContainerBuilder::CACHE_VALIDATED_KEY, $cache)
            && $cache[ContainerBuilder::CACHE_VALIDATED_KEY] !== true
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Deprecated container cache marker "%s" must be true when present.',
                ContainerBuilder::CACHE_VALIDATED_KEY,
            ));
        }
    }

    private static function isDeferredCallableArraySpecification(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === [0, 1]
            && is_string($value[0])
            && $value[0] !== ''
            && is_string($value[1])
            && $value[1] !== '';
    }

    private static function isCallableArraySpecification(mixed $value): bool
    {
        if (!is_array($value)
            || array_keys($value) !== [0, 1]
            || !is_string($value[1])
            || $value[1] === ''
        ) {
            return false;
        }

        if (is_callable($value)) {
            return true;
        }

        return is_string($value[0])
            && $value[0] !== ''
            && (class_exists($value[0]) || interface_exists($value[0]))
            && method_exists($value[0], $value[1]);
    }
}
