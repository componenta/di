<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClassDefinitionArrayState;

use Componenta\DI\Definition\ClassDefinition;
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
