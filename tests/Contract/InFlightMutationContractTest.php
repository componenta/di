<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\ConfigKey;
use Fiber;

use function Componenta\DI\Tests\Support\container;

it('preserves a replacement made while the previous shared factory is suspended', function (): void {
    $container = container([
        ConfigKey::FACTORIES => [
            'service' => static function (): string {
                Fiber::suspend();
                return 'old';
            },
        ],
    ]);
    $fiber = new Fiber(static fn(): mixed => $container->get('service'));
    $fiber->start();
    $container->set('service', 'new');
    $fiber->resume();

    expect($fiber->getReturn())->toBe('old')
        ->and($container->get('service'))->toBe('new');
});

it('preserves alias retargeting without discarding the unchanged source instance', function (): void {
    $calls = 0;
    $container = container([
        ConfigKey::FACTORIES => [
            'old' => static function () use (&$calls): object {
                ++$calls;
                Fiber::suspend();
                return new \stdClass();
            },
        ],
    ]);
    $replacement = new \stdClass();
    $container->set('new', $replacement);
    $container->alias('service', 'old');
    $fiber = new Fiber(static fn(): mixed => $container->get('service'));
    $fiber->start();
    $container->alias('service', 'new');
    $fiber->resume();

    expect($container->get('service'))->toBe($replacement)
        ->and($container->get('old'))->toBe($fiber->getReturn())
        ->and($calls)->toBe(1);
});

it('preserves a replacement registered by its own delegator', function (): void {
    $container = container();
    $container->set('service', 'old');
    $container->delegator('service', static function (string $value) use ($container): string {
        if ($value === 'old') {
            $container->set('service', 'new');
        }
        return $value . ':decorated';
    });

    expect($container->get('service'))->toBe('old:decorated')
        ->and($container->get('service'))->toBe('new:decorated');
});

it('keeps shared instances when a factory mutates an unrelated registration', function (): void {
    $calls = 0;
    $container = container([
        ConfigKey::FACTORIES => [
            'service' => static function (\Componenta\Config\ContainerValue $container) use (&$calls): object {
                ++$calls;
                $container->get(\Componenta\DI\Container::class, \Componenta\DI\Container::class)->set('unrelated', 'value');
                return new \stdClass();
            },
        ],
    ]);

    expect($container->get('service'))->toBe($container->get('service'))
        ->and($container->get('unrelated'))->toBe('value')
        ->and($calls)->toBe(1);
});

it('does not restore a deferred delegator invalidated while its factory is suspended', function (): void {
    $container = container([
        ConfigKey::FACTORIES => [
            'decorator' => static function (): \Closure {
                Fiber::suspend();
                return static fn(string $value): string => $value . ':old';
            },
        ],
    ]);
    $container->set('service', 'base');
    $container->delegator('service', 'decorator');
    $fiber = new Fiber(static fn(): mixed => $container->get('service'));
    $fiber->start();
    $container->set('decorator', static fn(string $value): string => $value . ':new');
    $fiber->resume();

    expect($fiber->getReturn())->toBe('base:old')
        ->and($container->get('service'))->toBe('base:new');
});
