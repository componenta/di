<?php

declare(strict_types=1);

use Componenta\DI\ProxyFactory;

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
