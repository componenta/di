<?php

declare(strict_types=1);

namespace Componenta\DI;

/** Configuration keys owned and consumed by the DI package. */
final class ConfigKey
{
    public const string DEPENDENCIES = \Componenta\Config\ConfigKey::DEPENDENCIES;
    public const string FACTORIES = \Componenta\Config\ConfigKey::FACTORIES;
    public const string INVOKABLES = \Componenta\Config\ConfigKey::INVOKABLES;
    public const string ALIASES = \Componenta\Config\ConfigKey::ALIASES;
    public const string DELEGATORS = \Componenta\Config\ConfigKey::DELEGATORS;
    public const string SERVICES = \Componenta\Config\ConfigKey::SERVICES;

    /** Custom parameter resolvers: priority => service/callable/instance. */
    public const string PARAMETER_RESOLVERS = \Componenta\Config\ConfigKey::PARAMETER_RESOLVERS;

    /** Replace the default parameter resolver chain. */
    public const string PARAMETER_RESOLVERS_REPLACE = \Componenta\Config\ConfigKey::PARAMETER_RESOLVERS_REPLACE;

    /** Custom runtime attribute handlers in registration order. */
    public const string ATTRIBUTE_HANDLERS = \Componenta\Config\ConfigKey::ATTRIBUTE_HANDLERS;

    /** Replace all built-in lifecycle/property handlers. */
    public const string ATTRIBUTE_HANDLERS_REPLACE
        = \Componenta\Config\ConfigKey::ATTRIBUTE_HANDLERS_REPLACE;

    /**
     * Returns only dependency keys implemented by the 3.x DI runtime.
     *
     * Do not delegate this list to componenta/config: that package also keeps
     * historical keys for compatibility with older consumers. Accepting those
     * keys here would silently preserve removed generated-resolver settings.
     *
     * @return list<string>
     */
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
            self::ATTRIBUTE_HANDLERS,
            self::ATTRIBUTE_HANDLERS_REPLACE,
        ];
    }

    private function __construct() {}
}
