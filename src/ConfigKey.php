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

    /** Generated EntryResolver PHP file loaded before ReflectionResolver. */
    public const string GENERATED_ENTRY_RESOLVER_FILE
        = \Componenta\Config\ConfigKey::GENERATED_ENTRY_RESOLVER_FILE;

    /** Release/deploy identifier that replaces per-build source hashing. */
    public const string GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
        = \Componenta\Config\ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT;

    /** @return list<string> */
    public static function dependencyKeys(): array
    {
        return \Componenta\Config\ConfigKey::dependencyKeys();
    }
}
