<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\CircularDependencyException;
use Fiber;
use LogicException;

use function Componenta\DI\Tests\Support\container;

test('numeric decorated service ids permit unrelated alias registration', function (): void {
    $container = container();
    $container->set('123', 'base');
    $container->set('target', 'target');
    $container->delegator('123', static fn(string $value): string => $value . ':decorated');

    expect($container->get('123'))->toBe('base:decorated');

    $container->alias('unrelated', 'target');

    expect($container->get('unrelated'))->toBe('target')
        ->and($container->get('123'))->toBe('base:decorated');
});

test('numeric callable ids invalidate decorated services after replacement', function (): void {
    $container = container();
    $container->set('123', static fn(string $value): string => $value . ':old');
    $container->set('456', 'base');
    $container->delegator('456', '123');

    expect($container->get('456'))->toBe('base:old');

    $container->set('123', static fn(string $value): string => $value . ':new');

    expect($container->get('456'))->toBe('base:new');
});

test('retargeting a numeric callable alias invalidates its dependent decorations', function (): void {
    $container = container();
    $container->set('old', static fn(string $value): string => $value . ':old');
    $container->set('new', static fn(string $value): string => $value . ':new');
    $container->set('456', 'base');
    $container->alias('123', 'old');
    $container->delegator('456', '123');

    expect($container->get('456'))->toBe('base:old');

    $container->alias('123', 'new');

    expect($container->get('456'))->toBe('base:new');
});

test('cycle diagnostics preserve numeric service ids as strings', function (bool $fresh, bool $inFiber): void {
    $container = container();
    $calls = 0;
    $first = static function () use ($container, $fresh, &$calls): mixed {
        if (++$calls > 2) {
            throw new LogicException('The service cycle was not stopped after revisiting its entry.');
        }
        return $fresh ? $container->make('123') : $container->get('123');
    };
    $second = static fn(): mixed => $fresh ? $container->make('-7') : $container->get('-7');
    $container->set('123', Definition::factory($second));
    $container->set('-7', Definition::factory($first));
    $resolve = static function () use ($first): CircularDependencyException {
        try {
            $first();
        } catch (CircularDependencyException $exception) {
            return $exception;
        }
        throw new LogicException('The service cycle unexpectedly resolved.');
    };

    if ($inFiber) {
        $fiber = new Fiber($resolve);
        $fiber->start();
        $exception = $fiber->getReturn();
        if (!$exception instanceof CircularDependencyException) {
            throw new LogicException('Expected a circular dependency diagnostic.');
        }
    } else {
        $exception = $resolve();
    }

    expect($exception->chain)->toBe(['123', '-7', '123']);
})->with([
    'shared resolution' => [false, false],
    'fresh resolution' => [true, false],
    'shared resolution in Fiber' => [false, true],
    'fresh resolution in Fiber' => [true, true],
]);
