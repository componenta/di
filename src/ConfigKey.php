<?php

declare(strict_types=1);

namespace Componenta\DI;

/** Configuration keys consumed by DI v5. */
final class ConfigKey
{
    public const string DEPENDENCIES = \Componenta\Config\ConfigKey::DEPENDENCIES;
    public const string FACTORIES = \Componenta\Config\ConfigKey::FACTORIES;
    public const string INVOKABLES = \Componenta\Config\ConfigKey::INVOKABLES;
    public const string ALIASES = \Componenta\Config\ConfigKey::ALIASES;
    public const string DELEGATORS = \Componenta\Config\ConfigKey::DELEGATORS;
    public const string SERVICES = \Componenta\Config\ConfigKey::SERVICES;

    /** priority => resolver instance/service/factory/[service, method] */
    public const string PARAMETER_RESOLVERS = \Componenta\Config\ConfigKey::PARAMETER_RESOLVERS;
    public const string PARAMETER_RESOLVERS_REPLACE = \Componenta\Config\ConfigKey::PARAMETER_RESOLVERS_REPLACE;

    public const string ATTRIBUTE_DEFINITIONS = 'attribute_definitions';
    public const string ATTRIBUTE_DEFINITIONS_REPLACE = 'attribute_definitions_replace';
    public const string ATTRIBUTE_CAPABILITIES = 'attribute_capabilities';

    /** @return list<string> */
    public static function dependencyKeys(): array
    {
        return [
            self::FACTORIES,
            self::INVOKABLES,
            self::ALIASES,
            self::DELEGATORS,
            self::SERVICES,
            self::PARAMETER_RESOLVERS,
            self::PARAMETER_RESOLVERS_REPLACE,
            self::ATTRIBUTE_DEFINITIONS,
            self::ATTRIBUTE_DEFINITIONS_REPLACE,
            self::ATTRIBUTE_CAPABILITIES,
        ];
    }

    private function __construct() {}
}
