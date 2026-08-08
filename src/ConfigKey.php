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
    public const string ATTRIBUTE_HANDLERS = 'attribute_handlers';

    /** Replace all built-in lifecycle/property handlers. */
    public const string ATTRIBUTE_HANDLERS_REPLACE = 'attribute_handlers_replace';

    /** Generated EntryResolver PHP file loaded before ReflectionResolver. */
    public const string GENERATED_ENTRY_RESOLVER_FILE = 'generated_entry_resolver_file';

    /** Release/deploy identifier that replaces per-build source hashing. */
    public const string GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
        = 'generated_entry_resolver_release_fingerprint';

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
            self::ATTRIBUTE_HANDLERS,
            self::ATTRIBUTE_HANDLERS_REPLACE,
            self::GENERATED_ENTRY_RESOLVER_FILE,
            self::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT,
        ];
    }
}
