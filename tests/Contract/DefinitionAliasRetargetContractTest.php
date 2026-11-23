<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DefinitionAliasRetarget;

use Closure;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use WeakReference;

final readonly class Product
{
    public function __construct(public string $value = 'replacement') {}
}

test('definition registration invalidates its original target when validation retargets the requested alias', function (bool $invokable): void {
    $unrelated = new Product('unrelated');
    $container = (new ContainerBuilder())
        ->addFactory('original', static fn(): Product => new Product('original'))
        ->addFactory('other', static fn(): Product => new Product('other'))
        ->addAlias('selected', 'original')
        ->addAlias('original.sibling', 'original')
        ->addAlias('other.sibling', 'other')
        ->addAlias('other.cold', 'other')
        ->addService('unrelated', $unrelated)
        ->build();
    foreach (['selected', 'original', 'original.sibling', 'other', 'other.sibling', 'other.cold'] as $id) {
        $container->delegator($id, static fn(Product $entry): Product => new Product($entry->value . ':' . $id));
    }
    $previous = [];
    foreach (['selected', 'original', 'original.sibling', 'other', 'other.sibling', 'unrelated'] as $id) {
        $previous[$id] = $container->get($id);
    }

    /** @var class-string<Product> $class */
    $class = __NAMESPACE__ . '\\DeferredProduct_' . bin2hex(random_bytes(5));
    $duringValidation = [];
    $loader = static function (string $requested) use ($container, $class, &$duringValidation): void {
        if ($requested === $class) {
            $container->alias('selected', 'other');
            $duringValidation['selected'] = $container->get('selected');
            $duringValidation['other.cold'] = $container->get('other.cold');
            class_alias(Product::class, $class);
        }
    };
    spl_autoload_register($loader);
    try {
        $container->set('selected', $invokable ? new InvokableDefinition($class) : ClassDefinition::create($class));
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($container->make('original'))->toEqual(new Product());
    foreach (['original', 'original.sibling'] as $id) {
        $current = $container->get($id);
        expect($current)->toEqual(new Product('replacement:' . $id))
            ->and($current)->not->toBe($previous[$id])
            ->and($container->get($id))->toBe($current);
    }

    $selected = $container->get('selected');
    expect($selected)->toEqual(new Product('other:selected'))
        ->and($selected)->toBe($duringValidation['selected'] ?? null)
        ->and($container->get('selected'))->toBe($selected)
        ->and($container->get('other.cold'))->toBe($duringValidation['other.cold'] ?? null)
        ->and($container->make('selected'))->toEqual(new Product('other'));
    foreach (['other', 'other.sibling', 'unrelated'] as $id) {
        expect($container->get($id))->toBe($previous[$id]);
    }
})->with(['class definition' => false, 'invokable definition' => true]);

final readonly class ParameterizedProduct
{
    public function __construct(public object $argument) {}
}

test('definition admission leaves routes warmed after its original owner becomes an alias', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory('original', static fn(): Product => new Product('original'))
        ->addFactory('other', static fn(): Product => new Product('other'))
        ->addAlias('selected', 'original')
        ->build();
    foreach (['original', 'selected'] as $id) {
        $container->delegator($id, static fn(Product $entry): Product => new Product($entry->value . ':' . $id));
    }
    $duringValidation = [];
    $duringRelease = [];
    $argument = new class (static function () use ($container, &$duringRelease): void {
        $duringRelease[] = [$container->get('original'), $container->get('selected')];
    }) {
        public function __construct(private readonly Closure $release) {}

        public function __destruct()
        {
            ($this->release)();
        }
    };
    $reference = WeakReference::create($argument);
    /** @var class-string<ParameterizedProduct> $class */
    $class = __NAMESPACE__ . '\\DeferredParameterizedProduct_' . bin2hex(random_bytes(5));
    $loader = static function (string $requested) use ($container, $class, &$duringValidation): void {
        if ($requested === $class) {
            $container->alias('original', 'other');
            $duringValidation = [$container->get('original'), $container->get('selected')];
            class_alias(ParameterizedProduct::class, $class, false);
        }
    };

    spl_autoload_register($loader);
    try {
        $definition = ClassDefinition::create($class)->constructor([$argument]);
        unset($argument);
        $container->set('selected', $definition);
        unset($definition);
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($reference->get())->toBeNull()
        ->and($duringValidation)->toEqual([new Product('other:original'), new Product('other:selected')])
        ->and($duringRelease)->toBe([$duringValidation])
        ->and([$container->get('original'), $container->get('selected')])->toBe($duringValidation)
        ->and($container->make('original'))->toEqual(new Product('other'));
});
