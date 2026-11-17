<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Tests\Support\ContainerBuilder;
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

test('protected aliases never query external ownership callbacks', function (bool $warm): void {
    $container = (new ContainerBuilder())->build();
    $container->alias('protected.container', ContainerInterface::class);
    if ($warm) {
        expect($container->get('protected.container'))->toBe($container);
    }
    $external = new class () implements ContainerInterface {
        /** @var list<string> */
        public array $calls = [];

        public function has(string $id): bool
        {
            $this->calls[] = 'has:' . $id;
            return true;
        }

        public function get(string $id): mixed
        {
            $this->calls[] = 'get:' . $id;
            return 'external';
        }
    };
    $container->addContainer($external);

    expect($container->get('protected.container'))->toBe($container)
        ->and($container->has('protected.container'))->toBeTrue()
        ->and($external->calls)->toBe([])
        ->and($container->get('external.entry'))->toBe('external')
        ->and($external->calls)->toBe(['has:external.entry', 'get:external.entry']);
})->with(['cold' => false, 'warm' => true]);
