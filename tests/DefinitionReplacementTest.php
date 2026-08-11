<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\NotFoundException;

final readonly class ReplacementInvokableService {}
final readonly class ReplacementFactoryService {}
final readonly class ReplacementStoredService {}

it('uses the latest runtime definition when its resolver kind changes', function (): void {
    $container = minimalContainer();

    $container->set('service', Definition::factory(static fn() => 'from-factory'));
    expect($container->get('service'))->toBe('from-factory');

    $container->set('service', Definition::invokable(ReplacementInvokableService::class));

    expect($container->get('service'))->toBeInstanceOf(ReplacementInvokableService::class)
        ->and($container->make('service'))->toBeInstanceOf(ReplacementInvokableService::class);
});

it('does not retain a stale runtime definition after replacing it with a stored value', function (): void {
    $container = minimalContainer();

    $container->set(
        'service',
        Definition::factory(static fn() => new ReplacementFactoryService()),
    );
    expect($container->make('service'))->toBeInstanceOf(ReplacementFactoryService::class);

    $replacement = new ReplacementStoredService();
    $container->set('service', $replacement);

    expect($container->get('service'))->toBe($replacement)
        ->and(fn() => $container->make('service'))->toThrow(NotFoundException::class);
});
