<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;

interface ProxyInjectionContract
{
    public function value(): string;
}

final readonly class ProxyInjectionService implements ProxyInjectionContract
{
    public function __construct(private string $value = 'proxied') {}

    public function value(): string
    {
        return $this->value;
    }
}

final readonly class InterfaceProxyConsumer
{
    public function __construct(
        #[Proxy(ProxyInjectionService::class)]
        public ProxyInjectionContract $service,
    ) {}
}

final readonly class ServiceIdProxyConsumer
{
    public function __construct(
        #[Make('proxy.service'), Proxy(ProxyInjectionService::class)]
        public ProxyInjectionContract $service,
    ) {}
}

final readonly class AmbiguousInterfaceProxyConsumer
{
    public function __construct(
        #[Proxy]
        public ProxyInjectionContract $service,
    ) {}
}

it('uses an explicit concrete class for interface-typed virtual proxies', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            ProxyInjectionContract::class,
            static fn(): ProxyInjectionContract => new ProxyInjectionService(),
        )
        ->build();

    $consumer = $container->make(InterfaceProxyConsumer::class);

    expect($consumer->service)->toBeInstanceOf(ProxyInjectionService::class)
        ->and($consumer->service->value())->toBe('proxied');
});

it('separates an arbitrary service id from its concrete proxy class', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'proxy.service',
            static fn(): ProxyInjectionContract => new ProxyInjectionService('service-id'),
        )
        ->build();

    $consumer = $container->make(ServiceIdProxyConsumer::class);

    expect($consumer->service->value())->toBe('service-id');
});

it('rejects an interface proxy when no concrete class can be inferred', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            ProxyInjectionContract::class,
            static fn(): ProxyInjectionContract => new ProxyInjectionService(),
        )
        ->build();

    expect(fn () => $container->make(AmbiguousInterfaceProxyConsumer::class))
        ->toThrow(
            ResolutionException::class,
            'specify #[Proxy(ConcreteClass::class)]',
        );
});
