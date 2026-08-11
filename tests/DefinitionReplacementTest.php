<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Definition\Definition;

final readonly class ReplacementInvokableService {}

it('uses the latest runtime definition when its resolver kind changes', function (): void {
    $container = minimalContainer();

    $container->set('service', Definition::factory(static fn() => 'from-factory'));
    expect($container->get('service'))->toBe('from-factory');

    $container->set('service', Definition::invokable(ReplacementInvokableService::class));

    expect($container->get('service'))->toBeInstanceOf(ReplacementInvokableService::class)
        ->and($container->make('service'))->toBeInstanceOf(ReplacementInvokableService::class);
});
