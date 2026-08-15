<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ConcurrentResolutionException;

it('distinguishes cross-Fiber shared resolution from a dependency cycle', function (): void {
    $builds = 0;
    $container = (new ContainerBuilder())
        ->addFactory('fiber.shared', static function () use (&$builds): object {
            ++$builds;
            Fiber::suspend('factory-suspended');

            return new stdClass();
        })
        ->build();
    $first = new Fiber(static fn(): object => $container->get('fiber.shared'));
    $second = new Fiber(static fn(): object => $container->get('fiber.shared'));

    expect($first->start())->toBe('factory-suspended')
        ->and(fn() => $second->start())
        ->toThrow(ConcurrentResolutionException::class, 'another execution context');

    $first->resume();
    $resolved = $first->getReturn();

    expect($builds)->toBe(1)
        ->and($container->get('fiber.shared'))->toBe($resolved);
});
