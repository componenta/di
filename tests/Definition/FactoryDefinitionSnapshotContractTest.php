<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Definition\FactoryDefinitionSnapshotContract;

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

final class StaticFactory
{
    public static function create(): object
    {
        return self::hidden();
    }

    public static function alternate(): object
    {
        return (object) ['route' => 'selected alternate'];
    }

    private static function hidden(): object
    {
        return (object) ['route' => 'selected'];
    }
}

final class ReplacementStaticFactory
{
    public static function alternate(): object
    {
        return (object) ['route' => 'replacement'];
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

test('factory definition entry points capture callable slots before native autoloading', function (
    bool $facade,
    bool $invalidate,
): void {
    $requested = __NAMESPACE__ . '\\PendingFactory_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'create';
    /** @var callable-array $route */
    $route = [&$owner, &$method];
    $replacement = $invalidate ? $requested . '_Missing' : ReplacementStaticFactory::class;
    $loads = 0;
    $loader = static function (string $class) use ($requested, $replacement, &$owner, &$method, &$loads): void {
        if ($class === $requested) {
            ++$loads;
            $owner = $replacement;
            $method = 'alternate';
            class_alias(StaticFactory::class, $class, autoload: false);
        }
    };
    spl_autoload_register($loader);

    try {
        $definition = $facade ? Definition::factory(factory: $route) : new FactoryDefinition(value: $route);
        $container = (new ContainerBuilder())->addDefinition('product', $definition)->build();

        expect($definition->value)->toBe([$requested, 'create'])
            ->and($container->get('product'))->toEqual((object) ['route' => 'selected'])
            ->and($owner)->toBe($replacement)
            ->and($method)->toBe('alternate')
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['constructor' => false, 'facade' => true])
    ->with(['replaced route' => false, 'invalidated route' => true]);

test('factory definition entry points reject invalid callable arrays', function (bool $facade, array $route): void {
    $create = static function () use ($facade, $route): FactoryDefinition {
        // @phpstan-ignore argument.type, argument.type (Intentionally invalid native callable arrays.)
        return $facade ? Definition::factory(factory: $route) : new FactoryDefinition(value: $route);
    };

    expect($create)->toThrow(\TypeError::class);
})->with(['constructor' => false, 'facade' => true])
    ->with([
        'malformed array' => [[StaticFactory::class]],
        'private method' => [[StaticFactory::class, 'hidden']],
    ]);

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

    $first = $container->make('product');
    if (!$first instanceof Product) {
        throw new LogicException('Expected a product from the original owner.');
    }
    expect($first)->toEqual(new Product($original, 'create', 'original'))
        ->and($first->owner)->toBe($original)
        ->and($ownerId)->toBe('factory.owner')
        ->and($method)->toBe('create');
    $container->set('factory.owner', $replacement);

    $second = $container->make('product');
    if (!$second instanceof Product) {
        throw new LogicException('Expected a product from the replacement owner.');
    }
    expect($second)->toEqual(new Product($replacement, 'create', 'replacement'))
        ->and($second->owner)->toBe($replacement)
        ->and($second)->not->toBe($first)
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
