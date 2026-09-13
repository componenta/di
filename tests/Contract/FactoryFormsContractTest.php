<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryForms;

use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class OrdinaryTarget {}

final class MagicTarget
{
    public string $method = '';
    /** @var array<array-key,mixed> */
    public array $parameters = [];

    /** @param array<array-key,mixed> $parameters */
    public function __call(string $method, array $parameters): void
    {
        $this->method = $method;
        $this->parameters = $parameters;
    }
}

/** @param array<array-key,mixed> $specification */
test('malformed deferred factory specifications fail at container construction', function (array $specification): void {
    expect(fn() => (new ContainerBuilder())->addFactory('product', $specification)->build())
        ->toThrow(InvalidConfigurationException::class, 'Factory "product" has unsupported type array');
})->with([
    'missing method' => [['factory.owner']],
    'extra element' => [['factory.owner', 'create', 'extra']],
    'associative keys' => [['owner' => 'factory.owner', 'method' => 'create']],
    'non-string owner' => [[42, 'create']],
    'empty owner' => [['', 'create']],
    'non-string method' => [['factory.owner', 42]],
    'empty method' => [['factory.owner', '']],
]);

test('native internal factories accept the complete runtime argument pair', function (): void {
    $written = new \WeakMap();
    $container = (new ContainerBuilder())
        ->addFactory('native.write', [$written, 'offsetSet'])
        ->addFactory('native.search', 'in_array')
        ->build();

    expect($container->get('native.write'))->toBeNull()
        ->and($container->has('native.write'))->toBeTrue()
        ->and($written[$container->get(ContainerValue::class)])->toBe([])
        ->and($container->get('native.search'))->toBeFalse();
});

test('ClassDefinition rejects missing methods before creating the target', function (): void {
    expect(fn() => (new ContainerBuilder())->addDefinition(
        'product',
        ClassDefinition::create(OrdinaryTarget::class)->call('configure'),
    )->build())->toThrow(InvalidConfigurationException::class, 'calls missing method');
});

test('ClassDefinition permits configured methods implemented by public magic dispatch', function (): void {
    $container = (new ContainerBuilder())->addDefinition(
        'product',
        ClassDefinition::create(MagicTarget::class)->call('configure', ['value' => 'configured']),
    )->build();

    $result = $container->make('product');
    expect($result)->toBeInstanceOf(MagicTarget::class);
    if (!$result instanceof MagicTarget) {
        throw new \LogicException('Expected the configured magic target.');
    }
    expect($result->method)->toBe('configure')
        ->and($result->parameters)->toBe(['value' => 'configured']);
});
