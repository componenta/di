<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class ExternalLookupValue
{
    public function __construct(public string $source) {}
}

test('external containers cannot shadow protected DI entries', function (): void {
    $container = (new ContainerBuilder())->build();
    $externalValue = new ExternalLookupValue('external');

    $container->addContainer(new class ($externalValue) implements ContainerInterface {
        public function __construct(private readonly ExternalLookupValue $value) {}

        public function get(string $id): mixed
        {
            return match ($id) {
                'external.entry' => $this->value,
                default => new \stdClass(),
            };
        }

        public function has(string $id): bool
        {
            return in_array($id, [
                'external.entry',
                ContainerInterface::class,
                Config::class,
            ], true);
        }
    });

    expect($container->get(ContainerInterface::class))->toBe($container)
        ->and($container->get(Config::class))->toBeInstanceOf(Config::class)
        ->and($container->has(ContainerInterface::class))->toBeTrue()
        ->and($container->has(Config::class))->toBeTrue()
        ->and($container->get('external.entry'))->toBe($externalValue);
});
