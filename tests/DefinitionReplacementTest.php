<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Fixture\ReplacementFactoryService;
use Componenta\DI\Tests\Fixture\ReplacementInvokableService;
use Componenta\DI\Tests\Fixture\ReplacementStoredService;

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

it('removes stale runtime definitions from every resolver kind before storing a value', function (): void {
    $container = minimalContainer();

    $container->set(
        'service',
        Definition::factory(static fn() => new ReplacementFactoryService()),
    );
    $container->set('service', Definition::invokable(ReplacementInvokableService::class));
    expect($container->make('service'))->toBeInstanceOf(ReplacementInvokableService::class);

    $replacement = new ReplacementStoredService();
    $container->set('service', $replacement);

    expect($container->get('service'))->toBe($replacement)
        ->and(fn() => $container->make('service'))->toThrow(NotFoundException::class);
});

it('keeps configured make semantics when a stored value only overrides get()', function (): void {
    $container = minimalBuilder()
        ->addFactory('service', static fn() => new ReplacementFactoryService())
        ->build();

    $replacement = new ReplacementStoredService();
    $container->set('service', $replacement);

    expect($container->get('service'))->toBe($replacement)
        ->and($container->make('service'))->toBeInstanceOf(ReplacementFactoryService::class);
});

it('keeps the previous stored value when a replacement definition is invalid', function (): void {
    $container = minimalContainer();
    $previous = new ReplacementStoredService();
    $container->set('service', $previous);

    expect(fn() => $container->set(
        'service',
        ClassDefinition::create(DateTimeInterface::class),
    ))->toThrow(InvalidConfigurationException::class)
        ->and($container->get('service'))->toBe($previous);
});
