<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Configuration\AliasConfigurationContract;

use Componenta\DI\Container;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionClass;

test('standalone alias configuration rejects malformed ids and targets with a configuration error', function (string|int $id, string|int $target): void {
    $constructor = new ReflectionClass(Container::class);

    expect(fn() => $constructor->newInstance(new CompositeResolver(), (new ContainerBuilder())->build(), [$id => $target]))
        ->toThrow(InvalidConfigurationException::class, 'Alias ids and targets must be strings.');
})->with([
    'numeric id' => [7, 'service'],
    'numeric target' => ['alias', 7],
    'numeric id and target' => [7, 8],
]);

test('standalone alias configuration rejects a cycle before any resolution is requested', function (): void {
    expect(fn() => new Container(new CompositeResolver(), (new ContainerBuilder())->build(), [
        'first' => 'second',
        'second' => 'third',
        'third' => 'first',
    ]))->toThrow(CircularDependencyException::class, 'Circular alias reference:');
});

test('bulk alias validation reports the first closing edge and its original path', function (): void {
    $constructor = new ReflectionClass(Container::class);

    expect(fn() => $constructor->newInstance(new CompositeResolver(), (new ContainerBuilder())->build(), [
        'first' => 'second',
        'second' => 'third',
        'third' => 'first',
        'bad' => 7,
    ]))->toThrow(CircularDependencyException::class, 'third -> first -> second -> third');
});
