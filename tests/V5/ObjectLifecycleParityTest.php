<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ResolutionException;

interface LifecycleProxyContract
{
    public function value(): string;
}

final readonly class LifecycleProxyService implements LifecycleProxyContract
{
    public function __construct(private string $value = 'proxied') {}

    public function value(): string
    {
        return $this->value;
    }
}

final readonly class LifecycleIncompatibleProxyService
{
    public function value(): string
    {
        return 'incompatible';
    }
}

final readonly class LifecycleProxyConsumer
{
    public function __construct(
        #[Proxy(LifecycleProxyService::class)]
        public LifecycleProxyContract $service,
    ) {}
}

final readonly class LifecycleServiceIdProxyConsumer
{
    public function __construct(
        #[Make('lifecycle.proxy.service'), Proxy(LifecycleProxyService::class)]
        public LifecycleProxyContract $service,
    ) {}
}

final readonly class LifecycleAmbiguousProxyConsumer
{
    public function __construct(
        #[Proxy]
        public LifecycleProxyContract $service,
    ) {}
}

final readonly class LifecycleIncompatibleProxyConsumer
{
    public function __construct(
        #[Proxy(LifecycleIncompatibleProxyService::class)]
        public LifecycleProxyContract $service,
    ) {}
}

final readonly class LifecycleWrongBackingProxyConsumer
{
    public function __construct(
        #[Make('lifecycle.wrong.proxy.service'), Proxy(LifecycleProxyService::class)]
        public LifecycleProxyContract $service,
    ) {}
}

final class LifecycleMakeCycleA
{
    public function __construct(
        #[Make]
        public LifecycleMakeCycleB $dependency,
    ) {}
}

final class LifecycleMakeCycleB
{
    public function __construct(
        #[Make]
        public LifecycleMakeCycleA $dependency,
    ) {}
}

final class LifecyclePromotedInitTarget
{
    public function __construct(
        #[Init('strtoupper', ['initialized'])]
        public string $value = 'constructor',
    ) {}
}

final readonly class LifecycleReadonlyPromotedInitTarget
{
    public function __construct(
        #[Init('strtoupper', ['ignored'])]
        public string $value = 'constructor',
    ) {}
}

final class LifecycleInjectedDependency {}

abstract class LifecyclePrivateInjectedParent
{
    #[Inject]
    private LifecycleInjectedDependency $dependency;

    public function dependency(): LifecycleInjectedDependency
    {
        return $this->dependency;
    }
}

final class LifecyclePrivateInjectedChild extends LifecyclePrivateInjectedParent {}

test('Proxy keeps explicit concrete implementation for interface targets', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            LifecycleProxyContract::class,
            static fn(): LifecycleProxyContract => new LifecycleProxyService(),
        )
        ->build();
    $consumer = $container->make(LifecycleProxyConsumer::class);

    expect($consumer->service)->toBeInstanceOf(LifecycleProxyService::class)
        ->and($consumer->service->value())->toBe('proxied');
});

test('Make service id and Proxy concrete class remain independent', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'lifecycle.proxy.service',
            static fn(): LifecycleProxyContract => new LifecycleProxyService('service-id'),
        )
        ->build();

    expect($container->make(LifecycleServiceIdProxyConsumer::class)->service->value())
        ->toBe('service-id');
});

test('Proxy rejects an interface target without a concrete proxy class', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            LifecycleProxyContract::class,
            static fn(): LifecycleProxyContract => new LifecycleProxyService(),
        )
        ->build();

    expect(fn() => $container->make(LifecycleAmbiguousProxyConsumer::class))
        ->toThrow(ResolutionException::class, 'specify #[Proxy(ConcreteClass::class)]');
});

test('Proxy rejects a concrete class incompatible with the declared type', function (): void {
    expect(fn() => (new ContainerBuilder())->build()->make(LifecycleIncompatibleProxyConsumer::class))
        ->toThrow(ResolutionException::class, 'is incompatible with declared type');
});

test('Proxy rejects a backing object incompatible with the proxy class', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'lifecycle.wrong.proxy.service',
            static fn(): object => new \stdClass(),
        )
        ->build();
    $consumer = $container->make(LifecycleWrongBackingProxyConsumer::class);

    expect(fn() => $consumer->service->value())
        ->toThrow(\LogicException::class, 'must be an instance of');
});

test('Make detects cycles created by Make attributes', function (): void {
    expect(fn() => (new ContainerBuilder())->build()->make(LifecycleMakeCycleA::class))
        ->toThrow(CircularDependencyException::class);
});

test('Init overwrites a mutable promoted property after construction', function (): void {
    $entry = (new ContainerBuilder())->build()->make(LifecyclePromotedInitTarget::class);

    expect($entry->value)->toBe('INITIALIZED');
});

test('Init leaves an initialized readonly promoted property unchanged', function (): void {
    $entry = (new ContainerBuilder())->build()->make(LifecycleReadonlyPromotedInitTarget::class);

    expect($entry->value)->toBe('constructor');
});

test('Attribute processing includes private properties declared by ancestors', function (): void {
    $dependency = new LifecycleInjectedDependency();
    $container = (new ContainerBuilder())
        ->addService(LifecycleInjectedDependency::class, $dependency)
        ->build();

    expect($container->make(LifecyclePrivateInjectedChild::class)->dependency())->toBe($dependency);
});
