<?php

declare(strict_types=1);

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('exposes the callable executor through the narrow resolver contract', function (): void {
    $container = (new ContainerBuilder())->build();

    $resolver = $container->get(CallableResolverInterface::class);

    expect($resolver)
        ->toBe($container->get(CallableExecutorInterface::class))
        ->and($resolver)->toBeInstanceOf(CallableResolverInterface::class)
        ->and(fn() => $container->set(CallableResolverInterface::class, new stdClass()))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $container->alias(CallableResolverInterface::class, 'other'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $container->delegator(CallableResolverInterface::class, static fn($entry) => $entry))
        ->toThrow(InvalidConfigurationException::class);
});

it('rejects a conflicting callable resolver binding at build time', function (): void {
    expect(fn() => (new ContainerBuilder())
        ->addService(CallableResolverInterface::class, new stdClass())
        ->build())
        ->toThrow(InvalidConfigurationException::class);
});
