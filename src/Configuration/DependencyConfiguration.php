<?php

declare(strict_types=1);

namespace Componenta\DI\Configuration;

use Componenta\DI\AliasResolver;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Value\ValueFallbackDefinition;

/**
 * Normalizes the declarative v5 container configuration.
 *
 * @phpstan-type DependencyShape array{
 *     factories?: array<string, mixed>,
 *     invokables?: list<class-string>,
 *     aliases?: array<string, non-empty-string>,
 *     delegators?: array<string, list<mixed>>,
 *     services?: array<string, mixed>,
 *     attribute_definitions?: list<mixed>,
 *     attribute_capabilities?: list<CapabilityPolicy>,
 *     value_fallbacks?: list<mixed>
 * }
 */
final class DependencyConfiguration
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $dependencies
     * @param array<string, non-empty-string> $defaultAliases
     * @return DependencyShape
     */
    public static function normalize(array $dependencies, array $defaultAliases = []): array
    {
        self::assertShape($dependencies);

        $aliases = array_merge($defaultAliases, $dependencies[ConfigKey::ALIASES] ?? []);
        /** @var list<class-string> $invokables */
        $invokables = [];
        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $value) {
            if (!in_array($value, $invokables, true)) {
                $invokables[] = $value;
            }
        }

        $delegators = [];
        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $list) {
            $delegators[$id] = self::normalizeDelegatorList($list, $id);
        }

        /** @var DependencyShape $normalized */
        $normalized = array_filter([
            ConfigKey::FACTORIES => $dependencies[ConfigKey::FACTORIES] ?? [],
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::ALIASES => $aliases,
            ConfigKey::DELEGATORS => $delegators,
            ConfigKey::SERVICES => $dependencies[ConfigKey::SERVICES] ?? [],
            ConfigKey::ATTRIBUTE_DEFINITIONS => $dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS] ?? [],
            ConfigKey::ATTRIBUTE_CAPABILITIES => $dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? [],
            ConfigKey::VALUE_FALLBACKS => $dependencies[ConfigKey::VALUE_FALLBACKS] ?? [],
        ], static fn(mixed $value): bool => $value !== []);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $cache
     * @return DependencyShape
     */
    public static function dependenciesFromCache(array $cache, int $expectedVersion): array
    {
        $allowed = ['version' => true, ConfigKey::DEPENDENCIES => true];
        foreach ($cache as $key => $_) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported cache envelope key "%s".',
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

        /** @var array<string, mixed> $dependencies */
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
        return $dependencies;
    }

    /**
     * @param array<string, mixed> $dependencies
     * @phpstan-assert DependencyShape $dependencies
     */
    public static function assertShape(array &$dependencies): void
    {
        $allowed = array_fill_keys(ConfigKey::dependencyKeys(), true);
        foreach ($dependencies as $key => $_) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container dependency key "%s".',
                    (string) $key,
                ));
            }
        }

        foreach (ConfigKey::dependencyKeys() as $key) {
            if (array_key_exists($key, $dependencies) && !is_array($dependencies[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be an array.',
                    $key,
                ));
            }
        }

        $invokableInput = $dependencies[ConfigKey::INVOKABLES] ?? [];
        if (!is_array($invokableInput)) {
            throw new InvalidConfigurationException('Invokables must be an array.');
        }
        /** @var list<class-string> $invokables */
        $invokables = [];
        /** @var array<string, non-empty-string> $invokableAliases */
        $invokableAliases = [];
        foreach ($invokableInput as $key => $value) {
            $class = $value instanceof InvokableDefinition ? $value->value : $value;
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException('Invokable entries must be non-empty class strings.');
            }
            /** @var class-string $class */
            $invokables[] = $class;
            if (is_string($key)) {
                self::assertInvokableAliasCompatible(
                    is_array($dependencies[ConfigKey::ALIASES] ?? null)
                        ? $dependencies[ConfigKey::ALIASES]
                        : [],
                    $key,
                    $class,
                );
                $invokableAliases[$key] = $class;
            }
        }
        if ($invokableInput !== []) {
            $dependencies[ConfigKey::INVOKABLES] = $invokables;
        }

        $aliasInput = $dependencies[ConfigKey::ALIASES] ?? [];
        if (!is_array($aliasInput)) {
            throw new InvalidConfigurationException('Aliases must be an array.');
        }
        /** @var array<string, non-empty-string> $aliases */
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

        $factoryInput = $dependencies[ConfigKey::FACTORIES] ?? [];
        if (!is_array($factoryInput)) {
            throw new InvalidConfigurationException('Factories must be an array.');
        }
        $factories = [];
        foreach ($factoryInput as $id => $factory) {
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

        $delegatorInput = $dependencies[ConfigKey::DELEGATORS] ?? [];
        if (!is_array($delegatorInput)) {
            throw new InvalidConfigurationException('Delegators must be an array.');
        }
        $delegators = [];
        foreach ($delegatorInput as $id => $value) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Delegator ids must be non-empty strings.');
            }
            $delegators[$id] = self::normalizeDelegatorList($value, $id);
        }
        if ($delegators !== []) {
            $dependencies[ConfigKey::DELEGATORS] = $delegators;
        }

        $serviceInput = $dependencies[ConfigKey::SERVICES] ?? [];
        if (!is_array($serviceInput)) {
            throw new InvalidConfigurationException('Services must be an array.');
        }
        $services = [];
        foreach ($serviceInput as $id => $service) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Service ids must be non-empty strings.');
            }
            $services[$id] = $service;
        }
        if ($services !== []) {
            $dependencies[ConfigKey::SERVICES] = $services;
        }

        self::assertListSection(
            $dependencies,
            ConfigKey::ATTRIBUTE_DEFINITIONS,
            static fn(mixed $value): bool => $value instanceof AttributeDefinition
                || is_callable($value)
                || (is_string($value) && $value !== ''),
            'Attribute definitions must be AttributeDefinition instances, factories or service ids.',
        );

        $capabilityInput = $dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? [];
        if (!is_array($capabilityInput) || ($capabilityInput !== [] && !array_is_list($capabilityInput))) {
            throw new InvalidConfigurationException('Attribute capabilities must be a list.');
        }
        $capabilities = [];
        foreach ($capabilityInput as $policy) {
            if (!$policy instanceof CapabilityPolicy) {
                throw new InvalidConfigurationException(
                    'Attribute capability entries must be CapabilityPolicy instances.',
                );
            }
            $capabilities[] = $policy;
        }
        if ($capabilities !== []) {
            $dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] = $capabilities;
        }

        self::assertListSection(
            $dependencies,
            ConfigKey::VALUE_FALLBACKS,
            static fn(mixed $value): bool => $value instanceof ValueFallbackDefinition
                || is_callable($value)
                || (is_string($value) && $value !== ''),
            'Value fallbacks must be ValueFallbackDefinition instances, factories or service ids.',
        );
    }

    /** @return list<mixed> */
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

    public static function normalizeDelegatorSpecification(mixed $delegator, string $id): mixed
    {
        if (is_callable($delegator)
            || (is_string($delegator) && $delegator !== '')
            || self::callablePair($delegator)
        ) {
            return $delegator;
        }

        throw new InvalidConfigurationException(sprintf(
            'Invalid delegator for "%s": %s.',
            $id,
            get_debug_type($delegator),
        ));
    }

    /**
     * @param array<string, non-empty-string> $aliases
     * @param class-string $target
     */
    public static function assertInvokableAliasCompatible(
        array $aliases,
        string $alias,
        string $target,
    ): void {
        if (isset($aliases[$alias]) && $aliases[$alias] !== $target) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable alias "%s" conflicts with existing alias target "%s".',
                $alias,
                $aliases[$alias],
            ));
        }
    }

    /**
     * @param array<string, non-empty-string> $aliases
     * @return array<string, non-empty-string>
     */
    public static function assertAliasesAcyclic(array $aliases): array
    {
        $resolver = new AliasResolver($aliases);
        foreach (array_keys($aliases) as $alias) {
            $resolver->resolve($alias);
        }
        return $aliases;
    }

    private static function callablePair(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === [0, 1]
            && (is_object($value[0]) || (is_string($value[0]) && $value[0] !== ''))
            && is_string($value[1])
            && $value[1] !== '';
    }

    /**
     * @param array<string, mixed> $dependencies
     * @param callable(mixed): bool $validator
     */
    private static function assertListSection(
        array &$dependencies,
        string $key,
        callable $validator,
        string $message,
    ): void {
        $input = $dependencies[$key] ?? [];
        if (!is_array($input) || ($input !== [] && !array_is_list($input))) {
            throw new InvalidConfigurationException(sprintf('%s must be a list.', $key));
        }
        foreach ($input as $value) {
            if (!$validator($value)) {
                throw new InvalidConfigurationException($message);
            }
        }
        if ($input !== []) {
            $dependencies[$key] = array_values($input);
        }
    }
}
