<?php

declare(strict_types=1);

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;

it('reports a missing lazy target as a ReflectionException', function (): void {
    expect(fn() => (new ProxyFactory())->makeLazy(
        'Componenta\\DI\\Tests\\MissingLazyTarget',
        static function (object $instance): void {},
    ))->toThrow(ReflectionException::class);
});

it('reports a missing proxy target as a ReflectionException', function (): void {
    expect(fn() => (new ProxyFactory())->makeProxy(
        'Componenta\\DI\\Tests\\MissingProxyTarget',
        static fn(object $proxy): object => new stdClass(),
    ))->toThrow(ReflectionException::class);
});

it('rejects conflicting object creation strategies', function (): void {
    $context = new ObjectCreationContext(new ReflectionClass(stdClass::class));
    $context->selectStrategy(CreationStrategy::Proxy);

    expect(fn() => $context->selectStrategy(CreationStrategy::Lazy))
        ->toThrow(InvalidConfigurationException::class);
});

it('accepts repeated selection of the same object creation strategy', function (): void {
    $context = new ObjectCreationContext(new ReflectionClass(stdClass::class));
    $context->selectStrategy(CreationStrategy::Lazy);
    $context->selectStrategy(CreationStrategy::Lazy);

    expect($context->strategy)->toBe(CreationStrategy::Lazy);
});
