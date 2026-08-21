<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class ExternalLookupValue
{
    public function __construct(public string $source) {}
}

test('external containers cannot shadow protected DI entries or aliases to them', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->alias('protected.container', ContainerInterface::class);
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
                ConfigAttribute::KEY,
                'protected.container',
            ], true);
        }
    });

    expect($container->get(ContainerInterface::class))->toBe($container)
        ->and($container->get(Config::class))->toBeInstanceOf(Config::class)
        ->and($container->get(ConfigAttribute::KEY))->toBeInstanceOf(Config::class)
        ->and($container->get('protected.container'))->toBe($container)
        ->and($container->has(ContainerInterface::class))->toBeTrue()
        ->and($container->has(Config::class))->toBeTrue()
        ->and($container->has(ConfigAttribute::KEY))->toBeTrue()
        ->and($container->has('protected.container'))->toBeTrue()
        ->and($container->get('external.entry'))->toBe($externalValue);
});
