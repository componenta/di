<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AliasPipelineInvalidation;

use Componenta\DI\Definition\Definition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;

final readonly class Entry
{
    public function __construct(public string $path) {}

    public function __invoke(string $value): string
    {
        return $value . ':' . $this->path;
    }
}

test('changing one alias pipeline preserves independent shared decorations', function (string $mutation): void {
    $container = (new ContainerBuilder())->build();
    $container->set('source', new Entry('source'));
    $container->set('other', new Entry('other'));
    $container->set('first.decorator', static fn(Entry $entry): Entry => new Entry($entry->path . ':first'));
    $container->alias('first', 'source');
    $container->alias('first.child', 'first');
    $container->alias('sibling', 'source');
    $container->delegator('first', 'first.decorator');
    $container->delegator('first.child', static fn(Entry $entry): Entry => new Entry($entry->path . ':child'));
    $container->delegator('sibling', static fn(Entry $entry): Entry => new Entry($entry->path . ':sibling'));
    $container->delegator('source', static fn(Entry $entry): Entry => new Entry($entry->path . ':canonical'));
    $container->set('product', 'value');
    $container->delegator('product', 'first');

    $canonical = $container->get('source');
    $sibling = $container->get('sibling');
    $child = $container->get('first.child');
    expect($container->get('product'))->toBe('value:source:first');

    match ($mutation) {
        'append delegator' => $container->delegator('first', static fn(Entry $entry): Entry => new Entry($entry->path . ':added')),
        'replace deferred decorator' => $container->set('first.decorator', static fn(Entry $entry): Entry => new Entry($entry->path . ':replacement')),
        'retarget alias' => $container->alias('first', 'other'),
        default => throw new LogicException('Unknown mutation.'),
    };
    $expectedProduct = match ($mutation) {
        'append delegator' => 'value:source:first:added',
        'replace deferred decorator' => 'value:source:replacement',
        default => 'value:other:first',
    };

    expect($container->get('source'))->toBe($canonical)
        ->and($container->get('sibling'))->toBe($sibling)
        ->and($container->get('product'))->toBe($expectedProduct)
        ->and($container->get('product'))->toBe($expectedProduct);

    if ($mutation === 'retarget alias') {
        $changedChild = $container->get('first.child');
        expect($changedChild)->not->toBe($child)
            ->and($changedChild)->toEqual(new Entry('other:child'));
    } else {
        expect($container->get('first.child'))->toBe($child);
    }
})->with(['append delegator', 'replace deferred decorator', 'retarget alias']);

test('replacing a base value still invalidates every alias pipeline and deferred dependent', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('source', new Entry('old'));
    $container->alias('first', 'source');
    $container->alias('child', 'first');
    $container->alias('sibling', 'source');
    foreach (['source', 'first', 'child', 'sibling'] as $id) {
        $container->delegator($id, static fn(Entry $entry): Entry => new Entry($entry->path . ':' . $id));
    }
    $container->set('product', 'value');
    $container->delegator('product', 'first');
    $previous = [];
    foreach (['source', 'first', 'child', 'sibling'] as $id) {
        $previous[$id] = $container->get($id);
    }
    expect($container->get('product'))->toBe('value:old:first');

    $container->set('child', new Entry('new'));

    foreach (['source', 'first', 'child', 'sibling'] as $id) {
        $current = $container->get($id);
        expect($current)->not->toBe($previous[$id])
            ->and($current)->toEqual(new Entry('new:' . $id))
            ->and($container->get($id))->toBe($current);
    }
    expect($container->get('product'))->toBe('value:new:first');
});

test('retargeted aliases stop depending on their previous source for invalidation', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('old', new Entry('old'));
    $container->set('new', new Entry('new'));
    $container->alias('first', 'old');
    $container->alias('child', 'first');
    $container->alias('sibling', 'old');
    foreach (['first', 'child', 'sibling'] as $id) {
        $container->delegator($id, static fn(Entry $entry): Entry => new Entry($entry->path . ':' . $id));
        $container->get($id);
    }
    $container->alias('first', 'new');
    $first = $container->get('first');
    $child = $container->get('child');

    $container->set('old', new Entry('replacement'));

    expect($container->get('first'))->toBe($first)
        ->and($container->get('child'))->toBe($child)
        ->and($container->get('sibling'))->toEqual(new Entry('replacement:sibling'));
});

test('retargeting an ancestor invalidates a cold alias suspended during source creation', function (): void {
    $container = (new ContainerBuilder())->build();
    $old = new Entry('old');
    $replacement = new Entry('replacement');
    $creations = 0;
    $container->set('old', Definition::factory(static function () use ($old, &$creations): Entry {
        ++$creations;
        Fiber::suspend('creating');
        return $old;
    }));
    $container->set('replacement', $replacement);
    $container->alias('parent', 'old');
    $container->alias('middle', 'parent');
    $container->alias('leaf', 'middle');
    $container->delegator('leaf', static fn(Entry $entry): Entry => new Entry($entry->path . ':leaf'));
    $fiber = new Fiber(static fn(): mixed => $container->get('leaf'));

    expect($fiber->start())->toBe('creating');
    $container->alias('parent', 'replacement');
    $fiber->resume();

    expect($fiber->getReturn())->toEqual(new Entry('old:leaf'))
        ->and($container->get('leaf'))->toEqual(new Entry('replacement:leaf'))
        ->and($container->get('middle'))->toBe($replacement)
        ->and($container->get('old'))->toBe($old)
        ->and($creations)->toBe(1);
});

test('a suspended alias only loses its result when the source value changes', function (bool $replaceBase): void {
    $container = (new ContainerBuilder())->build();
    $container->set('source', new Entry('old'));
    $container->alias('alias', 'source');
    $decorations = 0;
    $container->delegator('alias', static function (Entry $entry) use (&$decorations): Entry {
        if (++$decorations === 1) {
            Fiber::suspend('decorating');
        }
        return new Entry($entry->path . ':alias');
    });
    $fiber = new Fiber(static fn(): mixed => $container->get('alias'));
    expect($fiber->start())->toBe('decorating');

    if ($replaceBase) {
        $container->set('source', new Entry('new'));
    } else {
        $container->delegator('source', static fn(Entry $entry): Entry => new Entry($entry->path . ':canonical'));
    }
    $fiber->resume();
    $completed = $fiber->getReturn();
    $current = $container->get('alias');

    expect($completed)->toEqual(new Entry('old:alias'))
        ->and($current)->toEqual(new Entry(($replaceBase ? 'new' : 'old') . ':alias'))
        ->and($decorations)->toBe($replaceBase ? 2 : 1)
        ->and($container->get('alias'))->toBe($current);
    if ($replaceBase) {
        expect($current)->not->toBe($completed);
    } else {
        expect($current)->toBe($completed)
            ->and($container->get('source'))->toEqual(new Entry('old:canonical'));
    }
})->with(['canonical pipeline changed' => false, 'source replaced' => true]);
