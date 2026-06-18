<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;

/**
 * Injects environment variable values into parameters or properties.
 *
 * Retrieves values from Environment container (via Config::$environment).
 * Unlike #[Config], environment variable names are literal (no dot notation).
 *
 * @example Basic usage
 * ```php
 * public function __construct(
 *     #[Env('DATABASE_HOST')] string $dbHost,
 *     #[Env('DATABASE_PORT')] int $dbPort,
 *     #[Env('APP_DEBUG')] bool $debug,
 * ) {}
 * ```
 *
 * @example Implicit name (uses UPPER_SNAKE_CASE of parameter name)
 * ```php
 * public function __construct(
 *     #[Env] string $databaseHost,  // looks for DATABASE_HOST
 *     #[Env] int $appDebug,         // looks for APP_DEBUG
 * ) {}
 * ```
 *
 * Implicit-name conversion lives in
 * {@see \Componenta\DI\Resolver\EnvNameNormalizer::toEnvName()} - keeping the
 * attribute itself a pure DTO.
 *
 * @example With default value
 * ```php
 * public function __construct(
 *     #[Env('CACHE_TTL', default: 3600)] int $cacheTtl,
 *     #[Env('LOG_LEVEL', default: 'info')] string $logLevel,
 * ) {}
 * ```
 *
 * @example Property injection
 * ```php
 * class DatabaseService {
 *     #[Env('DB_CONNECTION')]
 *     private string $connection;
 *
 *     #[Env('DB_TIMEOUT', default: 30)]
 *     private int $timeout;
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
readonly class Env
{
    /**
     * @param string|null $name Environment variable name. Null uses parameter/property name converted to UPPER_SNAKE_CASE.
     * @param mixed $default Default value when variable is not found.
     */
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
    ) {}
}
