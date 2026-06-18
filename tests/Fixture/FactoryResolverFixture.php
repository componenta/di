<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ProxyType;

// Parameter fixtures

final class ParamMakeExplicitFixture
{
    public function handle(#[Make(Service::class)] Service $service): void {}
}

final class ParamMakeFromTypeFixture
{
    public function handle(#[Make] Service $service): void {}
}

final class ParamMakeFromNameFixture
{
    public function handle(#[Make] mixed $config): void {}
}

final class ParamMakeWithParamsFixture
{
    public function handle(#[Make(Service::class, params: ['timeout' => 30, 'retries' => 3])] Service $service): void {}
}

final class ParamMakeWithProxyFixture
{
    public function handle(#[Make(Service::class, proxy: ProxyType::LazyGhost)] Service $service): void {}
}

final class ParamProxyOnlyFixture
{
    public function handle(#[Proxy] Service $service): void {}
}

final class ParamProxyVirtualFixture
{
    public function handle(#[Proxy(ProxyType::Virtual)] Service $service): void {}
}

final class ParamMakeProxyPriorityFixture
{
    public function handle(
        #[Make(Service::class, proxy: ProxyType::Virtual)]
        #[Proxy(ProxyType::LazyGhost)]
        Service $service
    ): void {}
}

final class ParamNoMakeAttributeFixture
{
    public function handle(Service $service): void {}
}

// Property fixtures

final class PropMakeExplicitFixture
{
    #[Make(Service::class)]
    public Service $service;
}

final class PropMakeFromTypeFixture
{
    #[Make]
    public Service $service;
}

final class PropMakeFromNameFixture
{
    #[Make]
    public mixed $config;
}

final class PropMakeWithParamsFixture
{
    #[Make(Service::class, params: ['timeout' => 30])]
    public Service $service;
}

final class PropMakeWithProxyFixture
{
    #[Make(Service::class, proxy: ProxyType::LazyGhost)]
    public Service $service;
}

final class PropProxyOnlyFixture
{
    #[Proxy]
    public Service $service;
}

final class PropProxyVirtualFixture
{
    #[Proxy(ProxyType::Virtual)]
    public Service $service;
}

final class PropMakeProxyPriorityFixture
{
    #[Make(Service::class, proxy: ProxyType::Virtual)]
    #[Proxy(ProxyType::LazyGhost)]
    public Service $service;
}

final class PropNoMakeAttributeFixture
{
    public Service $service;
}
