<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

it('uses the first registered external container that owns the requested id', function () {
    $first = new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            return 'first';
        }

        public function has(string $id): bool
        {
            return $id === 'shared';
        }
    };
    $second = new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            return 'second';
        }

        public function has(string $id): bool
        {
            return $id === 'shared';
        }
    };
    $container = (new ContainerBuilder())->build();
    $container->addContainer($first);
    $container->addContainer($second);

    expect($container->get('shared'))->toBe('first');
});

it('external lookup uses only the original requested id before local aliases', function () {
    $external = new class () implements ContainerInterface {
        /** @var list<string> */
        public array $hasIds = [];

        public function get(string $id): mixed
        {
            return 'external';
        }

        public function has(string $id): bool
        {
            $this->hasIds[] = $id;

            return $id === 'local.target';
        }
    };
    $container = (new ContainerBuilder())
        ->addAlias('shortcut', 'local.target')
        ->addService('local.target', 'local')
        ->build();
    $container->addContainer($external);

    expect($container->get('shortcut'))->toBe('local')
        ->and($external->hasIds)->toBe(['shortcut']);
});

it('external container wins when it owns the original requested id', function () {
    $external = new class () implements ContainerInterface {
        /** @var list<string> */
        public array $hasIds = [];

        public function get(string $id): mixed
        {
            return 'external';
        }

        public function has(string $id): bool
        {
            $this->hasIds[] = $id;

            return $id === 'shortcut';
        }
    };
    $container = (new ContainerBuilder())
        ->addAlias('shortcut', 'local.target')
        ->addService('local.target', 'local')
        ->build();
    $container->addContainer($external);

    expect($container->get('shortcut'))->toBe('external')
        ->and($external->hasIds)->toBe(['shortcut']);
});
