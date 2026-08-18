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
    public const string ATTRIBUTE_DEFINITIONS = 'attribute_definitions';
    public const string ATTRIBUTE_CAPABILITIES = 'attribute_capabilities';
    public const string VALUE_FALLBACKS = 'value_fallbacks';

    /** @return list<string> */
    public static function dependencyKeys(): array
    {
        return [
            self::FACTORIES,
            self::INVOKABLES,
            self::ALIASES,
            self::DELEGATORS,
            self::SERVICES,
            self::ATTRIBUTE_DEFINITIONS,
            self::ATTRIBUTE_CAPABILITIES,
            self::VALUE_FALLBACKS,
        ];
    }

    private function __construct() {}
}
