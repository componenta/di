<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\Config\ConfigPath;

/**
 * Marks a parameter or property as configuration-bound.
 *
 * Pure DTO: declares **what** to read (`$path`, `$default`), but never **how**.
 * Lookup logic lives in {@see \Componenta\DI\Resolver\ConfigValueExtractor} so the
 * attribute can be created and inspected without pulling extraction
 * responsibilities into the metadata layer.
 *
 * Config path modes:
 *  - `null` - uses the parameter/property name as a literal key
 *  - `string` - literal key (no dot splitting)
 *  - {@see ConfigPath} - dot-notation traversal of nested arrays
 *
 * @example Implicit name (uses parameter name)
 * ```php
 * public function __construct(
 *     #[Config] string $database,  // $config['database']
 *     #[Config] int $timeout,      // $config['timeout']
 * ) {}
 * ```
 *
 * @example Literal key (string)
 * ```php
 * public function __construct(
 *     #[Config('cache.driver')] string $driver,  // $config['cache.driver']
 * ) {}
 * ```
 *
 * @example Dot notation with ConfigPath
 * ```php
 * use Componenta\Config\ConfigPath;
 *
 * public function __construct(
 *     #[Config(new ConfigPath('database.host'))] string $host,  // $config['database']['host']
 *     #[Config(new ConfigPath('database.port'))] int $port,     // $config['database']['port']
 * ) {}
 * ```
 *
 * @example With default value
 * ```php
 * public function __construct(
 *     #[Config(new ConfigPath('app.debug'), default: false)] bool $debug,
 *     #[Config('timezone', default: 'UTC')] string $timezone,
 * ) {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
readonly class Config
{
    /**
     * Container key for retrieving the root configuration.
     */
    public const string KEY = 'config';

    /**
     * @param string|ConfigPath|null $path Configuration path. Null uses parameter/property name, string for literal key, ConfigPath for dot notation.
     * @param mixed $default Default value when path is not found.
     */
    public function __construct(
        public string|ConfigPath|null $path = null,
        public mixed $default = DefaultValue::None,
    ) {}
}
