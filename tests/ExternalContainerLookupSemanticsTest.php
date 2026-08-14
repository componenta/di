<?php

declare(strict_types=1);

use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\ExternalContainerRegistry;
use Psr\Container\ContainerInterface;

test('external container registry is allocated only on first registration', function () {
    $container = (new ContainerBuilder())->build();
    $property = new ReflectionProperty(Container::class, 'externalContainers');

    expect($property->getValue($container))->toBeNull();

    $container->addContainer(new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException($id);
        }

        public function has(string $id): bool
        {
            return false;
        }
    });

    expect($property->getValue($container))->toBeInstanceOf(ExternalContainerRegistry::class);
});

test('external lookup uses only the original requested id before local aliases', function () {
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

test('external container wins when it owns the original requested id', function () {
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
