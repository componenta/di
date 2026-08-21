<?php

declare(strict_types=1);

namespace Componenta\DI;

/** DI v5 facade over the dependency keys defined by componenta/config v3. */
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

    public const string ATTRIBUTE_DEFINITIONS = \Componenta\Config\ConfigKey::ATTRIBUTE_DEFINITIONS;
    public const string ATTRIBUTE_DEFINITIONS_REPLACE = \Componenta\Config\ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE;
    public const string ATTRIBUTE_CAPABILITIES = \Componenta\Config\ConfigKey::ATTRIBUTE_CAPABILITIES;

    /** @return list<string> */
    public static function dependencyKeys(): array
    {
        return \Componenta\Config\ConfigKey::dependencyKeys();
    }

    private function __construct() {}
}
