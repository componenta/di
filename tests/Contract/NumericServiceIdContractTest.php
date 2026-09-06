<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

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
