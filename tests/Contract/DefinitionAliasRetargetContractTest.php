<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DefinitionAliasRetarget;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;

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
        ->addService('unrelated', $unrelated)
        ->build();
    foreach (['original', 'original.sibling', 'other', 'other.sibling'] as $id) {
        $container->delegator($id, static fn(Product $entry): Product => new Product($entry->value . ':' . $id));
    }
    $previous = [];
    foreach (['selected', 'original', 'original.sibling', 'other', 'other.sibling', 'unrelated'] as $id) {
        $previous[$id] = $container->get($id);
    }

    /** @var class-string<Product> $class */
    $class = __NAMESPACE__ . '\\DeferredProduct_' . bin2hex(random_bytes(5));
    $loader = static function (string $requested) use ($container, $class): void {
        if ($requested === $class) {
            $container->alias('selected', 'other');
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
    expect($selected)->toEqual(new Product('other'))
        ->and($container->get('selected'))->toBe($selected)
        ->and($container->make('selected'))->toEqual(new Product('other'));
    foreach (['other', 'other.sibling', 'unrelated'] as $id) {
        expect($container->get($id))->toBe($previous[$id]);
    }
})->with(['class definition' => false, 'invokable definition' => true]);
