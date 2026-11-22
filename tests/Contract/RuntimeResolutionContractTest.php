<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\ResolutionException;
use PHPUnit\Framework\Assert;

final class RuntimeFactoryProduct
{
    public function __construct(
        public readonly int $sequence,
        public readonly object $capture,
    ) {}
}

final class RuntimeConfiguredProduct
{
    public string $suffix = '';

    public function __construct(public readonly string $prefix) {}

    public function append(string $suffix): void
    {
        $this->suffix .= $suffix;
    }
}

final class RuntimeInvokableProduct {}

/** @param array<string, mixed> $sections */
function runtimeContainer(array $sections): Container
{
    $value = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions($sections),
    );

    expect($value->container)->toBeInstanceOf(Container::class);

    /** @var Container $container */
    $container = $value->container;

    return $container;
}

test('runtime services preserve values and identities including null false and resources', function (): void {
    $object = new \stdClass();
    $resource = fopen('php://memory', 'r+');
    if ($resource === false) {
        throw new \RuntimeException('Unable to open the runtime service fixture.');
    }

    try {
        $container = runtimeContainer([
            ConfigKey::SERVICES => [
                'mutable.object' => $object,
                'false.value' => false,
                'null.value' => null,
                'arbitrary id with spaces' => 'ready',
                'runtime.resource' => $resource,
            ],
        ]);

        expect($container->get('mutable.object'))->toBe($object)
            ->and($container->get('false.value'))->toBeFalse()
            ->and($container->get('null.value'))->toBeNull()
            ->and($container->get('arbitrary id with spaces'))->toBe('ready')
            ->and($container->get('runtime.resource'))->toBe($resource);
    } finally {
        fclose($resource);
    }
});

test('get is shared while make creates a fresh factory result with runtime parameters', function (): void {
    $capture = new \stdClass();
    $sequence = 0;
    $container = runtimeContainer([
        ConfigKey::FACTORIES => [
            RuntimeFactoryProduct::class => static function (
                ContainerValue $value,
                array $params,
            ) use (&$sequence, $capture): RuntimeFactoryProduct {
                expect($value->container)->toBeInstanceOf(Container::class);
                $requestedSequence = $params['sequence'] ?? ++$sequence;
                if (!is_int($requestedSequence)) {
                    throw new \InvalidArgumentException('The runtime sequence must be an integer.');
                }

                return new RuntimeFactoryProduct(
                    $requestedSequence,
                    $capture,
                );
            },
        ],
    ]);

    $shared = $container->get(RuntimeFactoryProduct::class);
    Assert::assertInstanceOf(RuntimeFactoryProduct::class, $shared);
    $fresh = $container->make(RuntimeFactoryProduct::class, ['sequence' => 99]);
    Assert::assertInstanceOf(RuntimeFactoryProduct::class, $fresh);

    expect($container->get(RuntimeFactoryProduct::class))->toBe($shared)
        ->and($fresh)->not->toBe($shared)
        ->and($shared->sequence)->toBe(1)
        ->and($fresh->sequence)->toBe(99)
        ->and($shared->capture)->toBe($capture)
        ->and($fresh->capture)->toBe($capture);
});

test('a non-object make failure permits retry and preserves the shared factory value', function (mixed $result, string $type): void {
    $calls = 0;
    $container = runtimeContainer([
        ConfigKey::FACTORIES => [
            'factory.result' => static function (ContainerValue $value, array $params) use ($result, &$calls): mixed {
                ++$calls;

                return $params['result'] ?? $result;
            },
        ],
    ]);
    expect($container->get('factory.result'))->toBe($result);

    try {
        $container->make('factory.result');
        Assert::fail('make() must reject a non-object factory result.');
    } catch (ResolutionException $failure) {
        expect($failure->serviceId)->toBe('factory.result')
            ->and($failure->actualType)->toBe($type)
            ->and($failure->getPrevious())->toBeNull();
    }

    $fresh = new \stdClass();
    expect($container->make('factory.result', ['result' => $fresh]))->toBe($fresh)
        ->and($container->get('factory.result'))->toBe($result)
        ->and($calls)->toBe(3);
})->with([
    'null result' => [null, 'null'],
    'false result' => [false, 'bool'],
    'array result' => [['value' => 'shared'], 'array'],
]);

test('class definitions invokables aliases and delegators share one ordered resolver path', function (): void {
    $container = runtimeContainer([
        ConfigKey::FACTORIES => [
            'configured.product' => ClassDefinition::create(RuntimeConfiguredProduct::class)
                ->constructor(['prefix' => 'base'])
                ->call('append', ['suffix' => ':method']),
        ],
        ConfigKey::INVOKABLES => [
            'invokable.alias' => RuntimeInvokableProduct::class,
        ],
        ConfigKey::ALIASES => [
            'product.alias' => 'configured.product',
        ],
        ConfigKey::DELEGATORS => [
            'product.alias' => [
                static function (RuntimeConfiguredProduct $entry): RuntimeConfiguredProduct {
                    $entry->append(':first');

                    return $entry;
                },
                static function (RuntimeConfiguredProduct $entry): RuntimeConfiguredProduct {
                    $entry->append(':second');

                    return $entry;
                },
            ],
        ],
    ]);

    $product = $container->get('product.alias');
    if (!$product instanceof RuntimeConfiguredProduct) {
        throw new \LogicException('The configured product alias resolved to an unexpected value.');
    }

    expect($product)->toBeInstanceOf(RuntimeConfiguredProduct::class)
        ->and($product->prefix)->toBe('base')
        ->and($product->suffix)->toBe(':method:first:second')
        ->and($container->get('invokable.alias'))->toBeInstanceOf(RuntimeInvokableProduct::class);
});
