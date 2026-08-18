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

/** Normalizes the declarative v5 container configuration. */
final class DependencyConfiguration
{
    private function __construct() {}

    /** @param array<string,mixed> $dependencies @param array<string,non-empty-string> $defaultAliases @return array<string,mixed> */
    public static function normalize(array $dependencies, array $defaultAliases = []): array
    {
        self::assertShape($dependencies);
        $aliases = array_merge($defaultAliases, $dependencies[ConfigKey::ALIASES] ?? []);
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

        return array_filter([
            ConfigKey::FACTORIES => $dependencies[ConfigKey::FACTORIES] ?? [],
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::ALIASES => $aliases,
            ConfigKey::DELEGATORS => $delegators,
            ConfigKey::SERVICES => $dependencies[ConfigKey::SERVICES] ?? [],
            ConfigKey::ATTRIBUTE_DEFINITIONS => $dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS] ?? [],
            ConfigKey::ATTRIBUTE_CAPABILITIES => $dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? [],
            ConfigKey::VALUE_FALLBACKS => $dependencies[ConfigKey::VALUE_FALLBACKS] ?? [],
        ], static fn(mixed $value): bool => $value !== []);
    }

    /** @param array<string,mixed> $cache @return array<string,mixed> */
    public static function dependenciesFromCache(array $cache, int $expectedVersion): array
    {
        $allowed = ['version' => true, ConfigKey::DEPENDENCIES => true];
        foreach ($cache as $key => $_) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf('Unsupported cache envelope key "%s".', (string) $key));
            }
        }
        if (($cache['version'] ?? null) !== $expectedVersion) {
            throw new InvalidConfigurationException(sprintf('Unsupported container cache version; expected %d.', $expectedVersion));
        }
        $dependencies = $cache[ConfigKey::DEPENDENCIES] ?? [];
        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException('Container cache dependencies must be an array.');
        }

        $factories = $dependencies[ConfigKey::FACTORIES] ?? null;
        if (is_array($factories)) {
            foreach ($factories as $id => $factory) {
                if ($factory instanceof CompiledFactoryDefinition) {
                    $factories[$id] = $factory->encode();
                }
            }
            $dependencies[ConfigKey::FACTORIES] = $factories;
        }

        return $dependencies;
    }

    /** @param array<string,mixed> $dependencies */
    public static function assertShape(array &$dependencies): void
    {
        $allowed = array_fill_keys(ConfigKey::dependencyKeys(), true);
        foreach ($dependencies as $key => $_) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf('Unsupported container dependency key "%s".', (string) $key));
            }
        }

        foreach (ConfigKey::dependencyKeys() as $key) {
            if (array_key_exists($key, $dependencies) && !is_array($dependencies[$key])) {
                throw new InvalidConfigurationException(sprintf('Container dependency "%s" must be an array.', $key));
            }
        }

        $invokables = $dependencies[ConfigKey::INVOKABLES] ?? [];
        foreach ($invokables as $key => $class) {
            if ($class instanceof InvokableDefinition) {
                $class = $class->value;
                $invokables[$key] = $class;
            }
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException('Invokable entries must be non-empty class strings.');
            }
        }
        if (array_key_exists(ConfigKey::INVOKABLES, $dependencies)) {
            $dependencies[ConfigKey::INVOKABLES] = $invokables;
        }

        $aliases = $dependencies[ConfigKey::ALIASES] ?? [];
        foreach ($aliases as $alias => $target) {
            if (!is_string($alias) || $alias === '' || !is_string($target) || $target === '') {
                throw new InvalidConfigurationException('Aliases must map non-empty string ids to non-empty string targets.');
            }
        }

        foreach ($dependencies[ConfigKey::FACTORIES] ?? [] as $id => $factory) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Factory ids must be non-empty strings.');
            }
            if ($factory instanceof FactoryDefinition) {
                FactorySpecificationValidator::assertValid($id, $factory);
                $dependencies[ConfigKey::FACTORIES][$id] = $factory->value;
                continue;
            }
            if (!$factory instanceof DefinitionInterface || $factory instanceof ClassDefinition || $factory instanceof CompiledFactoryDefinition) {
                FactorySpecificationValidator::assertValid($id, $factory);
            }
        }

        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $value) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Delegator ids must be non-empty strings.');
            }
            self::normalizeDelegatorList($value, $id);
        }

        foreach ($dependencies[ConfigKey::SERVICES] ?? [] as $id => $_) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Service ids must be non-empty strings.');
            }
        }

        foreach ($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS] ?? [] as $definition) {
            if (!$definition instanceof AttributeDefinition && !is_callable($definition) && !is_string($definition)) {
                throw new InvalidConfigurationException('Attribute definitions must be AttributeDefinition instances, factories or service ids.');
            }
        }
        foreach ($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? [] as $policy) {
            if (!$policy instanceof CapabilityPolicy) {
                throw new InvalidConfigurationException('Attribute capability entries must be CapabilityPolicy instances.');
            }
        }
        foreach ($dependencies[ConfigKey::VALUE_FALLBACKS] ?? [] as $fallback) {
            if (!$fallback instanceof ValueFallbackDefinition && !is_callable($fallback) && !is_string($fallback)) {
                throw new InvalidConfigurationException('Value fallbacks must be ValueFallbackDefinition instances, factories or service ids.');
            }
        }
    }

    /** @return list<mixed> */
    public static function normalizeDelegatorList(mixed $value, string $id): array
    {
        $items = self::callablePair($value) ? [$value] : (is_array($value) && array_is_list($value) ? $value : [$value]);
        return array_map(static fn(mixed $item): mixed => self::normalizeDelegatorSpecification($item, $id), $items);
    }

    public static function normalizeDelegatorSpecification(mixed $delegator, string $id): mixed
    {
        if (is_callable($delegator) || (is_string($delegator) && $delegator !== '') || self::callablePair($delegator)) {
            return $delegator;
        }
        throw new InvalidConfigurationException(sprintf('Invalid delegator for "%s": %s.', $id, get_debug_type($delegator)));
    }

    /** @param array<string,non-empty-string> $aliases */
    public static function assertInvokableAliasCompatible(array $aliases, string $alias, string $target): void
    {
        if (isset($aliases[$alias]) && $aliases[$alias] !== $target) {
            throw new InvalidConfigurationException(sprintf('Invokable alias "%s" conflicts with existing alias target "%s".', $alias, $aliases[$alias]));
        }
    }

    /** @return array<string,non-empty-string> */
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
}
