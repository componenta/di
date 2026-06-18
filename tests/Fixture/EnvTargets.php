<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Env;

/**
 * Fixture targets for EnvResolver tests.
 */
final class EnvTargets
{
    #[Env('DATABASE_HOST')]
    public string $explicitName;

    #[Env]
    public string $databaseHost; // derives DATABASE_HOST

    #[Env('CACHE_TTL', default: 3600)]
    public int $withIntDefault;

    #[Env('MISSING_VAR')]
    public string $missingNoDefault;

    #[Env('FEATURE_FLAG')]
    public bool $flag;

    #[Env('PORT')]
    public int $port;

    #[Env('RATE')]
    public float $rate;

    #[Env('RAW_MIXED')]
    public mixed $raw;

    public string $unattributed;

    public function byParameters(
        #[Env('DATABASE_HOST')] string $host,
        #[Env] string $databaseHost,
        #[Env('CACHE_TTL', default: 3600)] int $ttl,
        #[Env('MISSING_VAR')] string $missing,
        string $plain,
    ): void {}
}
