<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\Definition;
use LogicException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

test('opaque service ids cannot collide with the presence-check guard', function (): void {
    $container = container();
    $container->set('ready', true);
    $container->set("\0has:ready", Definition::factory(
        static fn(ContainerValue $context): bool => $context->has('ready'),
    ));

    expect($container->get("\0has:ready"))->toBeTrue();
});

test('opaque service ids cannot collide with the external-lookup guard', function (): void {
    $container = container();
    $container->addContainer(new class () implements ContainerInterface {
        public function has(string $id): bool
        {
            return $id === 'ready';
        }

        public function get(string $id): string
        {
            if (!$this->has($id)) {
                throw new LogicException('Unexpected external lookup: ' . $id);
            }
            return 'external-ready';
        }
    });
    $container->set("\0external:ready", Definition::factory(
        static fn(ContainerValue $context): mixed => $context->get('ready'),
    ));

    expect($container->get("\0external:ready"))->toBe('external-ready');
});
