<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Definition\ClassDefinitionArrayStateContract;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Product
{
    /** @var array<array-key, mixed> */
    public array $configured = [];

    /** @param array<array-key, mixed> $payload */
    public function __construct(public array $payload) {}

    /** @param array<array-key, mixed> $payload */
    public function configure(array $payload): void
    {
        $this->configured = $payload;
    }
}

test('class definition arrays preserve the next append key across admission and resolution', function (
    bool $runtime,
    ?int $existingKey,
    int $removedKey,
    int $nextKey,
): void {
    $payload = $existingKey === null ? [] : [$existingKey => 'existing'];
    $payload[$removedKey] = 'removed';
    unset($payload[$removedKey]);
    $definition = ClassDefinition::create(Product::class)
        ->constructor(['payload' => ['nested' => $payload]])
        ->call('configure', ['payload' => ['nested' => $payload]]);
    $builder = new ContainerBuilder();
    if (!$runtime) {
        $builder->addDefinition('product', $definition);
    }
    $container = $builder->build();
    if ($runtime) {
        $container->set('product', $definition);
    }

    foreach ([$container->get('product'), $container->make('product')] as $product) {
        if (!$product instanceof Product) {
            throw new \LogicException('Expected a configured product.');
        }
        foreach ([$product->payload, $product->configured] as $arguments) {
            $resolved = $arguments['nested'];
            if (!is_array($resolved)) {
                throw new \LogicException('Expected the configured nested array.');
            }
            $resolved[] = 'next';
            expect($resolved)->toBe($existingKey === null
                ? [$nextKey => 'next']
                : [$existingKey => 'existing', $nextKey => 'next']);
        }
    }
})->with(['bootstrap' => false, 'runtime' => true])
    ->with([
        'positive nonempty' => [3, 20, 21],
        'positive empty' => [null, 20, 21],
        'negative nonempty' => [-7, -3, -2],
        'negative empty' => [null, -3, -2],
    ]);

test('class definition arrays retain their final available integer key and append exhaustion', function (): void {
    $payload = [PHP_INT_MAX => 'removed'];
    unset($payload[PHP_INT_MAX]);
    $container = (new ContainerBuilder())
        ->addDefinition('product', new ClassDefinition(Product::class, ['payload' => $payload]))
        ->build();

    foreach ([$container->get('product'), $container->make('product')] as $product) {
        if (!$product instanceof Product) {
            throw new \LogicException('Expected a configured product.');
        }
        $resolved = $product->payload;
        $resolved[] = 'next';
        expect($resolved)->toBe([PHP_INT_MAX => 'next']);
        expect(static function () use ($resolved): void {
            $resolved[] = 'exhausted';
        })->toThrow(\Error::class, 'Cannot add element to the array');
    }
});

test('class definition snapshots detach referenced slots without reordering mixed keys', function (): void {
    $value = 'initial';
    $service = new \stdClass();
    $payload = [7 => 'first', 'referenced' => &$value, 'dependency' => Definition::reference('dependency'), -2 => 'last'];
    $container = (new ContainerBuilder())
        ->addService('dependency', $service)
        ->addDefinition('product', ClassDefinition::create(Product::class)
            ->constructor(['payload' => $payload])
            ->call('configure', ['payload' => $payload]))
        ->build();
    $value = 'changed';

    foreach ([$container->get('product'), $container->make('product')] as $product) {
        if (!$product instanceof Product) {
            throw new \LogicException('Expected a configured product.');
        }
        expect($product->payload)->toBe([7 => 'first', 'referenced' => 'initial', 'dependency' => $service, -2 => 'last'])
            ->and($product->configured)->toBe([7 => 'first', 'referenced' => 'initial', 'dependency' => $service, -2 => 'last']);
    }
});

test('literal class definition arrays remain independent when resolved objects change them', function (): void {
    $payload = ['value' => 'original', 'nested' => ['value' => 'nested']];
    $container = (new ContainerBuilder())
        ->addDefinition('product', ClassDefinition::create(Product::class)
            ->constructor(['payload' => $payload])
            ->call('configure', ['payload' => $payload]))
        ->build();
    $first = $container->make('product');
    $second = $container->make('product');
    if (!$first instanceof Product) {
        throw new \LogicException('Expected a configured product.');
    }
    $first->payload['value'] = 'changed';
    $reference = &$first->configured['nested'];
    $reference = ['value' => 'changed'];

    foreach ([$second, $container->make('product')] as $product) {
        if (!$product instanceof Product) {
            throw new \LogicException('Expected a configured product.');
        }
        expect($product->payload)->toBe($payload)
            ->and($product->configured)->toBe($payload);
    }
});
