<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryDefinitionSnapshot;

use Componenta\DI\Definition\Definition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final readonly class Product
{
    public function __construct(
        public Factory $owner,
        public string $method,
        public string $label,
    ) {}
}

final class Factory
{
    public function __construct(public string $label) {}

    public function create(): Product
    {
        return new Product($this, 'create', $this->label);
    }

    public function alternate(): Product
    {
        return new Product($this, 'alternate', $this->label);
    }
}

test('factory definitions own their callable route before registration', function (): void {
    $original = new Factory('original');
    $replacement = new Factory('replacement');
    $owner = $original;
    $method = 'create';
    $definition = Definition::factory([1 => &$method, 0 => &$owner]);
    $route = $definition->value;
    if (!is_array($route)) {
        throw new \LogicException('Expected the configured array callable.');
    }
    expect(array_keys($route))->toBe([1, 0]);
    $route[0] = $replacement;
    $route[1] = 'alternate';
    expect([$owner, $method])->toBe([$original, 'create']);

    $owner = $replacement;
    $method = 'alternate';
    $container = (new ContainerBuilder())->addDefinition('product', $definition)->build();
    $original->label = 'updated business state';
    $product = $container->make('product');
    if (!$product instanceof Product) {
        throw new \LogicException('Expected a factory product.');
    }

    expect($product->owner)->toBe($original)
        ->and($product)->toEqual(new Product($original, 'create', 'updated business state'));
});

test('cold factory definition creation retains the supplied route through its own autoloading', function (string $route): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/FactoryDefinitionCold.php',
        $root . '/vendor/autoload.php',
        $route,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the cold factory definition fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr)
        ->and($stderr)->toBe('')
        ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'autoloads' => 1,
            'caller_rebound' => true,
            'retained_receiver' => true,
            'key_order' => [1, 0],
            'product' => 'create:updated business state',
        ]);
})->with(['direct constructor' => 'constructor', 'factory facade' => 'facade']);

test('callable definition admission snapshots routing references while retaining factory object identity', function (
    bool $runtime,
    bool $warm,
): void {
    $original = new Factory('original');
    $owner = $original;
    $method = 'create';
    $definition = new FactoryDefinition([&$owner, &$method]);
    $builder = new ContainerBuilder();
    if (!$runtime) {
        $builder->addDefinition('product', $definition);
    }
    $container = $builder->build();
    if ($runtime) {
        $container->set('product', $definition);
    }
    $shared = $warm ? $container->get('product') : null;

    $owner = new Factory('replacement');
    $method = 'alternate';
    $resolved = $container->get('product');
    $fresh = $container->make('product');

    if (!$resolved instanceof Product || !$fresh instanceof Product) {
        throw new \LogicException('Expected factory products retaining their callable owner.');
    }
    expect($resolved)->toEqual(new Product($original, 'create', 'original'))
        ->and($fresh)->toEqual(new Product($original, 'create', 'original'))
        ->and($resolved->owner)->toBe($original)
        ->and($fresh->owner)->toBe($original);
    if ($warm) {
        expect($resolved)->toBe($shared);
    }
    $original->label = 'updated business state';
    expect($container->make('product'))->toEqual(new Product($original, 'create', 'updated business state'));
})->with(['bootstrap' => false, 'runtime' => true])
    ->with(['cold' => false, 'warm' => true]);

test('deferred factory resolution preserves caller references and looks up the current owner on every make', function (): void {
    $original = new Factory('original');
    $replacement = new Factory('replacement');
    $ownerId = 'factory.owner';
    $method = 'create';
    $container = (new ContainerBuilder())
        ->addService('factory.owner', $original)
        ->addFactory('product', [&$ownerId, &$method])
        ->build();

    expect($container->make('product'))->toEqual(new Product($original, 'create', 'original'))
        ->and($ownerId)->toBe('factory.owner')
        ->and($method)->toBe('create');
    $container->set('factory.owner', $replacement);

    expect($container->make('product'))->toEqual(new Product($replacement, 'create', 'replacement'))
        ->and($ownerId)->toBe('factory.owner');
});

test('deferred factory admission snapshots its service id and method before cold and warm resolution', function (bool $warm): void {
    $original = new Factory('original');
    $ownerId = 'factory.owner';
    $method = 'create';
    $container = (new ContainerBuilder())
        ->addService('factory.owner', $original)
        ->addService('different.owner', new Factory('different'))
        ->addFactory('product', [&$ownerId, &$method])
        ->build();
    $shared = $warm ? $container->get('product') : null;

    $ownerId = 'different.owner';
    $method = 'alternate';
    $resolved = $container->get('product');
    $fresh = $container->make('product');

    expect($resolved)->toEqual(new Product($original, 'create', 'original'))
        ->and($fresh)->toEqual(new Product($original, 'create', 'original'));
    if ($warm) {
        expect($resolved)->toBe($shared);
    }
})->with(['cold' => false, 'warm' => true]);
