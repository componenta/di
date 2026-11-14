<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryDefinitionSnapshot;

use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;

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

    expect($resolved)->toEqual(new Product($original, 'create', 'original'))
        ->and($fresh)->toEqual(new Product($original, 'create', 'original'));
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
