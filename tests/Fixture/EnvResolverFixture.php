<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Env;

// Parameter fixtures

final class ParamEnvExplicitFixture
{
    public function handle(#[Env('DATABASE_HOST')] string $host): void {}
}

final class ParamEnvImplicitFixture
{
    public function handle(#[Env] string $databaseHost): void {}
}

final class ParamEnvIntFixture
{
    public function handle(#[Env('DATABASE_PORT')] int $port): void {}
}

final class ParamEnvBoolFixture
{
    public function handle(#[Env('APP_DEBUG')] bool $debug): void {}
}

final class ParamEnvFloatFixture
{
    public function handle(#[Env('RATE')] float $rate): void {}
}

final class ParamEnvArrayFixture
{
    public function handle(#[Env('ALLOWED_HOSTS')] array $hosts): void {}
}

final class ParamEnvDefaultFixture
{
    public function handle(#[Env('TIMEOUT', default: 30)] int $timeout): void {}
}

final class ParamEnvNewValueFixture
{
    public function handle(#[Env('NEW_VALUE')] string $value): void {}
}

final class ParamNoEnvFixture
{
    public function handle(string $value): void {}
}

// Property fixtures

final class PropEnvExplicitFixture
{
    #[Env('DATABASE_HOST')]
    public string $host;
}

final class PropEnvImplicitFixture
{
    #[Env]
    public string $databaseHost;
}

final class PropEnvIntFixture
{
    #[Env('DATABASE_PORT')]
    public int $port;
}

final class PropEnvBoolFixture
{
    #[Env('APP_DEBUG')]
    public bool $debug;
}

final class PropEnvFloatFixture
{
    #[Env('TAX_RATE')]
    public float $rate;
}

final class PropEnvArrayFixture
{
    #[Env('ALLOWED_IPS')]
    public array $ips;
}

final class PropEnvDefaultFixture
{
    #[Env('TIMEOUT', default: 60)]
    public int $timeout;
}

final class PropEnvNewValueFixture
{
    #[Env('NEW_VALUE')]
    public string $value;
}

final class PropNoEnvFixture
{
    public string $value;
}
