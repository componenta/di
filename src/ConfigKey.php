<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Configuration keys for DI container.
 *
 * @example Usage in config files
 * ```php
 * use Componenta\DI\ConfigKey;
 *
 * return [
 *     ConfigKey::DEPENDENCIES => [
 *         ConfigKey::FACTORIES => [...],
 *         ConfigKey::ALIASES => [...],
 *     ],
 * ];
 * ```
 */
final class ConfigKey
{

    // ==========================================================================
    // Top-level keys
    // ==========================================================================

    /** Root key for all DI configuration */
    public const string DEPENDENCIES = \Componenta\Config\ConfigKey::DEPENDENCIES;

    // ==========================================================================
    // Dependencies section keys
    // ==========================================================================

    /** Factory callables: id => callable|class-string */
    public const string FACTORIES = \Componenta\Config\ConfigKey::FACTORIES;

    /** Simple classes without dependencies: list<class-string> or id => class-string */
    public const string INVOKABLES = \Componenta\Config\ConfigKey::INVOKABLES;

    /** Service aliases: alias => target */
    public const string ALIASES = \Componenta\Config\ConfigKey::ALIASES;

    /** Classes for autowiring: list<class-string> */
    public const string AUTOWIRES = \Componenta\Config\ConfigKey::AUTOWIRES;

    /** Delegator factories: id => list<callable|class-string> */
    public const string DELEGATORS = \Componenta\Config\ConfigKey::DELEGATORS;

    /** Pre-instantiated services: id => instance */
    public const string SERVICES = \Componenta\Config\ConfigKey::SERVICES;

    /** app config key */
    public const string CONFIG = \Componenta\Config\ConfigKey::CONFIG;

    /** Custom parameter resolvers: priority => class-string|callable|ParameterResolverInterface */
    public const string PARAMETER_RESOLVERS = \Componenta\Config\ConfigKey::PARAMETER_RESOLVERS;

    /** Custom property resolvers: priority => class-string|callable|PropertyResolverInterface */
    public const string PROPERTY_RESOLVERS = \Componenta\Config\ConfigKey::PROPERTY_RESOLVERS;

    /** When true, the default parameter resolver chain is NOT installed; only the user-supplied resolvers are. */
    public const string PARAMETER_RESOLVERS_REPLACE = \Componenta\Config\ConfigKey::PARAMETER_RESOLVERS_REPLACE;

    /** When true, the default property resolver chain is NOT installed; only the user-supplied resolvers are. */
    public const string PROPERTY_RESOLVERS_REPLACE = \Componenta\Config\ConfigKey::PROPERTY_RESOLVERS_REPLACE;

    /** DI plan compiler mode: sparse by default, complete for legacy rollback. */
    public const string DI_PLANS_MODE = \Componenta\DI\Compile\PlanCompiler::MODE_CONFIG_KEY;

    /**
     * Get all dependency section keys.
     *
     * @return list<string>
     */
    public static function dependencyKeys(): array
    {
        return [
            self::FACTORIES,
            self::INVOKABLES,
            self::ALIASES,
            self::AUTOWIRES,
            self::DELEGATORS,
            self::SERVICES,
            self::CONFIG,
        ];
    }
}
