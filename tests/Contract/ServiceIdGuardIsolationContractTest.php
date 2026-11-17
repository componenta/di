<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\ContainerValue;
use Componenta\DI\Container;
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

test('external ownership callbacks can check distinct opaque ids without colliding across lookup operations', function (string $requested, string $inspected): void {
    $container = container();
    $container->set($requested, 'requested value');
    $container->set($inspected, true);
    $external = new class ($container, $requested, $inspected) implements ContainerInterface {
        public ?bool $available = null;

        public function __construct(
            private readonly Container $container,
            private readonly string $requested,
            private readonly string $inspected,
        ) {}

        public function has(string $id): bool
        {
            if ($id === $this->requested) {
                $this->available = $this->container->has($this->inspected);
            }
            return false;
        }

        public function get(string $id): never
        {
            throw new LogicException('This external container owns no entries.');
        }
    };
    $container->addContainer($external);

    expect($container->get($requested))->toBe('requested value')
        ->and($external->available)->toBeTrue()
        ->and($container->has($inspected))->toBeTrue()
        ->and($container->get($inspected))->toBeTrue();
})->with([
    'requested id with availability marker' => ["\0has:ready", 'ready'],
    'requested marker and inspected suffix' => ["\0has:ready", "ready\0external:"],
    'inspected id with external marker' => ['ready', "\0external:ready"],
    'requested suffix and inspected marker' => ["ready\0has:", "\0external:ready"],
]);
