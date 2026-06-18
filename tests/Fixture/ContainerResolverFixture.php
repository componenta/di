<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Inject;

// Parameter fixtures

final class ParamEntryIdFixture
{
    public function handle(#[EntryId('logger.file')] ServiceInterface $logger): void {}
}

final class ParamConfigLiteralFixture
{
    public function handle(#[Config('database_host')] string $host): void {}
}

final class ParamConfigPathFixture
{
    public function handle(#[Config(new ConfigPath('database.host'))] string $host): void {}
}

final class ParamConfigImplicitFixture
{
    public function handle(#[Config] int $timeout): void {}
}

final class ParamConfigDefaultFixture
{
    public function handle(#[Config('debug', default: false)] bool $debug): void {}
}

final class ParamAutowireFixture
{
    public function handle(ServiceInterface $service): void {}
}

final class ParamBuiltinTypeFixture
{
    public function handle(string $value): void {}
}

final class ParamUntypedFixture
{
    public function handle($value): void {}
}

// Property fixtures

final class PropEntryIdFixture
{
    #[EntryId('cache.redis')]
    public object $cache;
}

final class PropInjectFixture
{
    #[Inject]
    public ServiceInterface $service;
}

final class PropInjectNoTypeFixture
{
    #[Inject]
    public $value;
}

final class PropConfigLiteralFixture
{
    #[Config('database_host')]
    public string $host;
}

final class PropConfigPathFixture
{
    #[Config(new ConfigPath('database.host'))]
    public string $host;
}

final class PropConfigImplicitFixture
{
    #[Config]
    public int $timeout;
}

final class PropConfigDefaultFixture
{
    #[Config('debug', default: false)]
    public bool $debug;
}

final class PropNoAttributeFixture
{
    public string $value;
}

final class PropBuiltinTypeFixture
{
    public int $count;
}
