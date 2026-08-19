<?php

declare(strict_types=1);

namespace Componenta\DI\Configuration;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;

/**
 * Validates and canonicalizes declarative DI configuration.
 *
 * Integer parameter-resolver keys are semantic priorities and are never
 * reindexed by this class.
 *
 * @phpstan-type DependencyShape array{
 *     factories?: array<string,mixed>,
 *     invokables?: list<class-string>,
 *     aliases?: array<string,non-empty-string>,
 *     delegators?: array<string,list<callable|string|array{object|string,string}>>,
 *     services?: array<string,mixed>,
 *     parameter_resolvers?: array<int,mixed>,
 *     parameter_resolvers_replace?: bool,
 *     attribute_definitions?: list<mixed>,
 *     attribute_definitions_replace?: bool,
 *     attribute_capabilities?: list<CapabilityPolicy>
 * }
 */
final class DependencyConfiguration
{
    private function __construct() {}

    /**
     * @param array<array-key,mixed> $dependencies
     * @param array<string,non-empty-string> $defaultAliases
     * @return DependencyShape
     */
    public static function normalize(array $dependencies, array $defaultAliases = []): array
    {
        self::assertShape($dependencies);

        /** @var array<string,non-empty-string> $configuredAliases */
        $configuredAliases = self::section($dependencies, ConfigKey::ALIASES);
        $aliases = array_merge($defaultAliases, $configuredAliases);

        /** @var list<class-string> $configuredInvokables */
        $configuredInvokables = self::section($dependencies, ConfigKey::INVOKABLES);
        $invokables = [];
        foreach ($configuredInvokables as $value) {
            if (!in_array($value, $invokables, true)) {
                $invokables[] = $value;
            }
        }

        /** @var array<string,list<callable|string|array{object|string,string}>> $configuredDelegators */
        $configuredDelegators = self::section($dependencies, ConfigKey::DELEGATORS);
        $delegators = [];
        foreach ($configuredDelegators as $id => $value) {
            $delegators[$id] = self::normalizeDelegatorList($value, $id);
        }

        /** @var array<string,mixed> $factories */
        $factories = self::section($dependencies, ConfigKey::FACTORIES);
        /** @var array<string,mixed> $services */
        $services = self::section($dependencies, ConfigKey::SERVICES);
        /** @var array<int,mixed> $parameterResolvers */
        $parameterResolvers = self::section($dependencies, ConfigKey::PARAMETER_RESOLVERS);
        /** @var list<mixed> $attributeDefinitions */
        $attributeDefinitions = self::section($dependencies, ConfigKey::ATTRIBUTE_DEFINITIONS);
        /** @var list<CapabilityPolicy> $attributeCapabilities */
        $attributeCapabilities = self::section($dependencies, ConfigKey::ATTRIBUTE_CAPABILITIES);

        /** @var DependencyShape $normalized */
        $normalized = array_filter([
            ConfigKey::FACTORIES => $factories,
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::ALIASES => $aliases,
            ConfigKey::DELEGATORS => $delegators,
            ConfigKey::SERVICES => $services,
            ConfigKey::PARAMETER_RESOLVERS => $parameterResolvers,
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => self::replaceFlag(
                $dependencies,
                ConfigKey::PARAMETER_RESOLVERS_REPLACE,
            ),
            ConfigKey::ATTRIBUTE_DEFINITIONS => $attributeDefinitions,
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => self::replaceFlag(
                $dependencies,
                ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE,
            ),
            ConfigKey::ATTRIBUTE_CAPABILITIES => $attributeCapabilities,
        ], static fn(mixed $value): bool => $value !== [] && $value !== false);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $cache
     * @return DependencyShape
     */
    public static function dependenciesFromCache(array $cache, int $expectedVersion): array
    {
        $allowed = ['version' => true, ConfigKey::DEPENDENCIES => true];
        foreach ($cache as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container cache envelope key "%s".',
                    (string) $key,
                ));
            }
        }

        if (($cache['version'] ?? null) !== $expectedVersion) {
            throw new InvalidConfigurationException(sprintf(
                'Unsupported container cache version; expected %d.',
                $expectedVersion,
            ));
        }

        $dependencies = $cache[ConfigKey::DEPENDENCIES] ?? [];
        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException('Container cache dependencies must be an array.');
        }

        /** @var array<array-key,mixed> $dependencies */
        $factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        if (is_array($factories)) {
            foreach ($factories as $id => $factory) {
                if ($factory instanceof CompiledFactoryDefinition) {
                    $factories[$id] = $factory->encode();
                }
            }
            $dependencies[ConfigKey::FACTORIES] = $factories;
        }

        self::assertShape($dependencies);
        /** @var DependencyShape $dependencies */
        return $dependencies;
    }

    /**
     * @param array<array-key,mixed> $dependencies
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
            ConfigKey::ATTRIBUTE_DEFINITIONS,
            ConfigKey::ATTRIBUTE_CAPABILITIES,
        ] as $key) {
            if (array_key_exists($key, $dependencies) && !is_array($dependencies[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be an array; got %s.',
                    $key,
                    get_debug_type($dependencies[$key]),
                ));
            }
        }

        foreach ([ConfigKey::PARAMETER_RESOLVERS_REPLACE, ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE] as $key) {
            if (array_key_exists($key, $dependencies) && !is_bool($dependencies[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be bool; got %s.',
                    $key,
                    get_debug_type($dependencies[$key]),
                ));
            }
        }

        self::normalizeInvokablesAndAliases($dependencies);
        self::validateFactories($dependencies);
        self::validateDelegators($dependencies);
        self::validateServices($dependencies);
        self::validateParameterResolvers($dependencies);
        self::validateAttributeDefinitions($dependencies);
        self::validateCapabilities($dependencies);
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function normalizeInvokablesAndAliases(array &$dependencies): void
    {
        $invokableInput = self::section($dependencies, ConfigKey::INVOKABLES);
        $invokables = [];
        $invokableAliases = [];
        foreach ($invokableInput as $key => $value) {
            $class = $value instanceof InvokableDefinition ? $value->value : $value;
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException('Invokable entries must be non-empty class strings.');
            }
            $invokables[] = $class;
            if (is_string($key)) {
                $invokableAliases[$key] = $class;
            }
        }
        if ($invokableInput !== []) {
            $dependencies[ConfigKey::INVOKABLES] = $invokables;
        }

        $aliasInput = self::section($dependencies, ConfigKey::ALIASES);
        $aliases = [];
        foreach ($aliasInput as $alias => $target) {
            if (!is_string($alias) || $alias === '' || !is_string($target) || $target === '') {
                throw new InvalidConfigurationException(
                    'Aliases must map non-empty string ids to non-empty string targets.',
                );
            }
            $aliases[$alias] = $target;
        }
        foreach ($invokableAliases as $alias => $target) {
            self::assertInvokableAliasCompatible($aliases, $alias, $target);
            $aliases[$alias] ??= $target;
        }
        if ($aliases !== []) {
            self::assertAliasesAcyclic($aliases);
            $dependencies[ConfigKey::ALIASES] = $aliases;
        }
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function validateFactories(array &$dependencies): void
    {
        $input = self::section($dependencies, ConfigKey::FACTORIES);
        $factories = [];
        foreach ($input as $id => $factory) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Factory ids must be non-empty strings.');
            }
            if ($factory instanceof FactoryDefinition) {
                FactorySpecificationValidator::assertValid($id, $factory);
                $factory = $factory->value;
            } elseif (!$factory instanceof DefinitionInterface
                || $factory instanceof ClassDefinition
                || $factory instanceof CompiledFactoryDefinition
            ) {
                FactorySpecificationValidator::assertValid($id, $factory);
            }
            $factories[$id] = $factory;
        }
        if ($factories !== []) {
            $dependencies[ConfigKey::FACTORIES] = $factories;
        }
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function validateDelegators(array &$dependencies): void
    {
        $input = self::section($dependencies, ConfigKey::DELEGATORS);
        $delegators = [];
        foreach ($input as $id => $value) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Delegator ids must be non-empty strings.');
            }
            $delegators[$id] = self::normalizeDelegatorList($value, $id);
        }
        if ($delegators !== []) {
            $dependencies[ConfigKey::DELEGATORS] = $delegators;
        }
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function validateServices(array $dependencies): void
    {
        foreach (self::section($dependencies, ConfigKey::SERVICES) as $id => $_service) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Service ids must be non-empty strings.');
            }
        }
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function validateParameterResolvers(array $dependencies): void
    {
        foreach (self::section($dependencies, ConfigKey::PARAMETER_RESOLVERS) as $priority => $resolver) {
            if (!is_int($priority)) {
                throw new InvalidConfigurationException(sprintf(
                    'Parameter resolver priority must be int; got %s.',
                    get_debug_type($priority),
                ));
            }
            if ($resolver instanceof ParameterResolverInterface) {
                continue;
            }
            self::assertExtensionSpecification($resolver, 'parameter resolver');
        }
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function validateAttributeDefinitions(array $dependencies): void
    {
        $input = self::section($dependencies, ConfigKey::ATTRIBUTE_DEFINITIONS);
        if ($input !== [] && !array_is_list($input)) {
            throw new InvalidConfigurationException('Attribute definitions must be configured as a list.');
        }
        foreach ($input as $definition) {
            if ($definition instanceof AttributeDefinition) {
                continue;
            }
            self::assertExtensionSpecification($definition, 'attribute definition');
        }
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function validateCapabilities(array $dependencies): void
    {
        $input = self::section($dependencies, ConfigKey::ATTRIBUTE_CAPABILITIES);
        if ($input !== [] && !array_is_list($input)) {
            throw new InvalidConfigurationException('Attribute capabilities must be configured as a list.');
        }
        foreach ($input as $policy) {
            if (!$policy instanceof CapabilityPolicy) {
                throw new InvalidConfigurationException(sprintf(
                    'Attribute capability entries must be %s; got %s.',
                    CapabilityPolicy::class,
                    get_debug_type($policy),
                ));
            }
        }
    }

    /** @return list<callable|string|array{object|string,string}> */
    public static function normalizeDelegatorList(mixed $value, string $id): array
    {
        $items = self::callablePair($value)
            ? [$value]
            : (is_array($value) && array_is_list($value) ? $value : [$value]);

        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = self::normalizeDelegatorSpecification($item, $id);
        }
        return $normalized;
    }

    /** @return callable|string|array{object|string,string} */
    public static function normalizeDelegatorSpecification(mixed $delegator, string $id): mixed
    {
        if (is_string($delegator) && $delegator !== '') {
            return $delegator;
        }
        if (self::deferredServiceMethod($delegator)) {
            return $delegator;
        }
        if (self::callablePair($delegator) || is_callable($delegator)) {
            return $delegator;
        }

        throw new InvalidConfigurationException(sprintf(
            'Invalid delegator for "%s": %s.',
            $id,
            get_debug_type($delegator),
        ));
    }

    public static function assertExtensionSpecification(mixed $extension, string $kind): void
    {
        if (is_callable($extension)
            || (is_string($extension) && $extension !== '')
            || self::deferredServiceMethod($extension)
        ) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            '%s specification must be an instance, callable, non-empty service id or [service-id, method]; got %s.',
            ucfirst($kind),
            get_debug_type($extension),
        ));
    }

    /** @param array<string,non-empty-string> $aliases */
    public static function assertInvokableAliasCompatible(array $aliases, string $alias, string $target): void
    {
        if (!array_key_exists($alias, $aliases)) {
            return;
        }

        $resolver = new AliasResolver($aliases);
        $existing = $resolver->resolve($alias);
        $requested = $resolver->resolve($target);
        if ($existing !== $requested) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable alias "%s" conflicts with existing target "%s"; requested "%s".',
                $alias,
                $existing,
                $requested,
            ));
        }
    }

    /**
     * @param array<string,non-empty-string> $aliases
     * @return array<string,non-empty-string>
     */
    public static function assertAliasesAcyclic(array $aliases): array
    {
        $resolver = new AliasResolver($aliases);
        foreach (array_keys($aliases) as $alias) {
            $resolver->resolve($alias);
        }
        return $aliases;
    }

    /** @phpstan-assert-if-true array{object|string,string} $value */
    private static function callablePair(mixed $value): bool
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

    /** @phpstan-assert-if-true array{non-empty-string,non-empty-string} $value */
    private static function deferredServiceMethod(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === [0, 1]
            && is_string($value[0])
            && $value[0] !== ''
            && is_string($value[1])
            && $value[1] !== '';
    }

    /**
     * @param array<array-key,mixed> $dependencies
     * @return array<array-key,mixed>
     */
    private static function section(array $dependencies, string $key): array
    {
        $value = $dependencies[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /** @param array<array-key,mixed> $dependencies */
    private static function replaceFlag(array $dependencies, string $key): bool
    {
        $value = $dependencies[$key] ?? false;
        return is_bool($value) ? $value : false;
    }
}
