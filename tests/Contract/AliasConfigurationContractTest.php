<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AliasConfiguration;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use ReflectionClass;

test('standalone alias configuration rejects malformed ids and targets with a configuration error', function (string|int $id, string|int $target): void {
    $constructor = new ReflectionClass(AliasResolver::class);

    expect(fn() => $constructor->newInstance([$id => $target]))
        ->toThrow(InvalidConfigurationException::class, 'Alias ids and targets must be strings.');
})->with([
    'numeric id' => [7, 'service'],
    'numeric target' => ['alias', 7],
    'numeric id and target' => [7, 8],
]);

test('standalone alias configuration rejects a cycle before any resolution is requested', function (): void {
    expect(fn() => new AliasResolver([
        'first' => 'second',
        'second' => 'third',
        'third' => 'first',
    ]))->toThrow(CircularDependencyException::class, 'Circular alias reference:');
});
