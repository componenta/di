<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DefinitionDeferredRetarget;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final readonly class Decorator
{
    public function __construct(public string $version = 'replacement') {}

    public function __invoke(Product $entry): Product
    {
        return new Product($entry->value . ':' . $this->version);
    }
}

test('definition registration invalidates deferred dependents from the alias graph after validation', function (bool $invokable): void {
    $container = (new ContainerBuilder())
        ->addFactory('original', static fn(): Decorator => new Decorator('original'))
        ->addFactory('other', static fn(): Decorator => new Decorator('other'))
        ->addAlias('incoming.decorator', 'other')
        ->addAlias('outgoing.decorator', 'original')
        ->addAlias('stable.decorator', 'original')
        ->build();
    foreach (['incoming', 'outgoing', 'stable'] as $id) {
        $container->set($id, new Product('value'));
        $container->delegator($id, $id . '.decorator');
    }
    $incomingBefore = $container->get('incoming');
    $outgoingBefore = $container->get('outgoing');
    $stableBefore = $container->get('stable');
    $incomingDuring = null;
    $outgoingDuring = null;

    /** @var class-string<Decorator> $class */
    $class = __NAMESPACE__ . '\\DeferredDecorator_' . bin2hex(random_bytes(5));
    $loader = static function (string $requested) use ($container, $class, &$incomingDuring, &$outgoingDuring): void {
        if ($requested === $class) {
            $container->alias('incoming.decorator', 'original');
            $container->alias('outgoing.decorator', 'other');
            $incomingDuring = $container->get('incoming');
            $outgoingDuring = $container->get('outgoing');
            class_alias(Decorator::class, $class);
        }
    };
    spl_autoload_register($loader);
    try {
        $container->set('original', $invokable ? new InvokableDefinition($class) : ClassDefinition::create($class));
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($incomingBefore)->toEqual(new Product('value:other'))
        ->and($outgoingBefore)->toEqual(new Product('value:original'))
        ->and($incomingDuring)->toEqual(new Product('value:original'))
        ->and($outgoingDuring)->toEqual(new Product('value:other'))
        ->and($container->get('original'))->toEqual(new Decorator());

    $incoming = $container->get('incoming');
    $stable = $container->get('stable');
    expect($incoming)->toEqual(new Product('value:replacement'))
        ->and($incoming)->not->toBe($incomingDuring)
        ->and($container->get('incoming'))->toBe($incoming)
        ->and($stable)->toEqual(new Product('value:replacement'))
        ->and($stable)->not->toBe($stableBefore)
        ->and($container->get('stable'))->toBe($stable)
        ->and($outgoingDuring)->not->toBe($outgoingBefore)
        ->and($container->get('outgoing'))->toBe($outgoingDuring);
})->with(['class definition' => false, 'invokable definition' => true]);
