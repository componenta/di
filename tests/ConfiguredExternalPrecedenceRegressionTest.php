<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class ConfiguredExternalPrecedenceValue
{
    public function __construct(public string $source) {}
}

test('configured resolver entries still defer to external containers before materialization', function () {
    $container = (new ContainerBuilder())
        ->addFactory(
            'configured.entry',
            static fn() => new ConfiguredExternalPrecedenceValue('configured'),
        )
        ->build();
    $container->addContainer(new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            return new ConfiguredExternalPrecedenceValue('external');
        }

        public function has(string $id): bool
        {
            return $id === 'configured.entry';
        }
    });

    expect($container->get('configured.entry')->source)->toBe('external');
});
