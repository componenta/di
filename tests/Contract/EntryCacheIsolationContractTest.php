<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\EntryCacheIsolation;

use Componenta\Config\ContainerValue;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;
use stdClass;

final readonly class Product
{
    public function __construct(
        public string $owner,
        public string $input,
        public object $dependency,
    ) {}
}

final readonly class ProductFactory
{
    public function __construct(private string $owner) {}

    /** @param array<array-key, mixed> $params */
    public function create(ContainerValue $container, array $params): Product
    {
        $input = $params['input'] ?? 'shared';
        $dependency = $container->get('dependency');
        if (!is_string($input) || !is_object($dependency)) {
            throw new LogicException('The factory received an unexpected input or dependency.');
        }
        return new Product($this->owner, $input, $dependency);
    }
}

final readonly class ProductDecorator
{
    public function __construct(private string $owner) {}

    public function decorate(Product $product): Product
    {
        return new Product($product->owner . ':' . $this->owner, $product->input, $product->dependency);
    }
}

final class MutableExternalEntries implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(public array $entries = []) {}

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new LogicException('Unexpected external lookup: ' . $id);
        }
        return $this->entries[$id];
    }
}

test('shared callable metadata preserves factory and delegator instances in each container', function (bool $deferred): void {
    $containers = [];
    $dependencies = [];
    foreach (['first', 'second'] as $owner) {
        $dependencies[$owner] = new stdClass();
        $factory = new ProductFactory($owner);
        $decorator = new ProductDecorator($owner . '-decorator');
        $containers[$owner] = (new ContainerBuilder())
            ->addService('dependency', $dependencies[$owner])
            ->addService('factory', $factory)
            ->addService('decorator', $decorator)
            ->addFactory('product', [$deferred ? 'factory' : $factory, 'create'])
            ->addDelegator('product', [$deferred ? 'decorator' : $decorator, 'decorate'])
            ->build();
    }

    $shared = [];
    foreach (['first', 'second', 'first', 'second'] as $owner) {
        $container = $containers[$owner];
        $product = $container->get('product');
        $fresh = $container->make('product', ['input' => $owner . '-runtime']);
        if (!$product instanceof Product || !$fresh instanceof Product) {
            throw new LogicException('Expected a product from both resolution paths.');
        }
        $shared[$owner] ??= $product;

        expect($product)->toBe($shared[$owner])
            ->and($product->owner)->toBe($owner . ':' . $owner . '-decorator')
            ->and($product->dependency)->toBe($dependencies[$owner])
            ->and($fresh->owner)->toBe($owner)
            ->and($fresh->input)->toBe($owner . '-runtime')
            ->and($fresh->dependency)->toBe($dependencies[$owner]);
    }

    $containers['first']->set('factory', new ProductFactory('replacement'));
    $containers['first']->set('decorator', new ProductDecorator('replacement-decorator'));
    $fresh = $containers['first']->make('product', ['input' => 'after-mutation']);
    $product = $containers['first']->get('product');
    if (!$fresh instanceof Product || !$product instanceof Product) {
        throw new LogicException('Expected a product after replacing the callable services.');
    }

    expect($fresh->owner)->toBe($deferred ? 'replacement' : 'first')
        ->and($fresh->input)->toBe('after-mutation')
        ->and($fresh->dependency)->toBe($dependencies['first'])
        ->and($product->owner)->toBe($deferred ? 'first:replacement-decorator' : 'first:first-decorator')
        ->and($product->dependency)->toBe($dependencies['first'])
        ->and($containers['second']->get('product'))->toBe($shared['second']);
})->with(['bound instances' => false, 'deferred service methods' => true]);

test('null and false factory results remain shared through warmed aliases', function (mixed $value): void {
    $calls = 0;
    $container = (new ContainerBuilder())
        ->addFactory('source', static function () use ($value, &$calls): mixed {
            ++$calls;
            return $value;
        })
        ->build();
    $container->alias('alias', 'source');

    expect($container->get('alias'))->toBe($value)
        ->and($container->get('source'))->toBe($value)
        ->and($container->get('alias'))->toBe($value)
        ->and($container->has('alias'))->toBeTrue()
        ->and($calls)->toBe(1);

    $replacement = new stdClass();
    $container->set('source', $replacement);

    expect($container->get('alias'))->toBe($replacement)
        ->and($container->get('source'))->toBe($replacement)
        ->and($calls)->toBe(1);
})->with(['null' => [null], 'false' => [false]]);

test('external ownership changes are observed without replacing the local shared value', function (): void {
    $local = new stdClass();
    $earlierValue = new stdClass();
    $laterValue = new stdClass();
    $container = (new ContainerBuilder())->addService('entry', $local)->build();
    expect($container->get('entry'))->toBe($local);

    $earlier = new MutableExternalEntries();
    $later = new MutableExternalEntries(['entry' => $laterValue]);
    $container->addContainer($earlier);
    $container->addContainer($later);
    expect($container->get('entry'))->toBe($laterValue);

    $earlier->entries['entry'] = $earlierValue;
    expect($container->get('entry'))->toBe($earlierValue);

    unset($earlier->entries['entry']);
    expect($container->get('entry'))->toBe($laterValue);

    unset($later->entries['entry']);
    expect($container->get('entry'))->toBe($local)
        ->and($container->has('entry'))->toBeTrue();
});
