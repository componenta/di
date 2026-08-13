<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Definition\Definition;
use Psr\Container\ContainerInterface;

final readonly class RuntimeDefinitionOwnershipValue
{
    public function __construct(public string $source) {}
}

test('runtime definitions installed through set take precedence over external containers', function () {
    $external = new class () implements ContainerInterface {
        public int $hasCalls = 0;

        public function get(string $id): mixed
        {
            return new RuntimeDefinitionOwnershipValue('external');
        }

        public function has(string $id): bool
        {
            ++$this->hasCalls;
            return $id === 'runtime.owned';
        }
    };
    $container = minimalContainer();
    $container->addContainer($external);
    $container->set(
        'runtime.owned',
        Definition::factory(static fn() => new RuntimeDefinitionOwnershipValue('local')),
    );

    $external->hasCalls = 0;

    expect($container->has('runtime.owned'))->toBeTrue()
        ->and($external->hasCalls)->toBe(0)
        ->and($container->get('runtime.owned')->source)->toBe('local')
        ->and($container->make('runtime.owned')->source)->toBe('local');
});
