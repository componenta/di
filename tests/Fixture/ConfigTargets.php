<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config;

final class ConfigTargets
{
    #[Config('database_host')]
    public string $explicitLiteral;

    #[Config]
    public string $timeout; // derived from property name

    #[Config(path: new ConfigPath('database.host'))]
    public string $nestedPath;

    #[Config('missing_key', default: 'fallback')]
    public string $withDefault;

    #[Config('absent_required')]
    public string $required;

    public string $unattributed;

    public function byParameters(
        #[Config('database_host')] string $host,
        #[Config] string $timeout,
        #[Config('missing_key', default: 42)] int $fallbackInt,
        string $plain,
    ): void {}
}
