<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;

final class NormalizationDelegator
{
    public function decorate(object $entry): object
    {
        return $entry;
    }
}

test('normalization omits unused dependency sections and retains explicit replacement', function (): void {
    expect(DependencyConfiguration::normalize([
        'factories' => [],
        'services' => [],
        'parameter_resolvers_replace' => true,
        'attribute_definitions_replace' => false,
    ]))->toBe(['parameter_resolvers_replace' => true]);
});

test('normalization unwraps factories without collapsing compatible alias chains', function (): void {
    $factory = static fn(): object => new \stdClass();
    $delegator = static fn(object $entry): object => $entry;
    $reference = new ReferenceDefinition('source');

    expect(DependencyConfiguration::normalize([
        'invokables' => ['entry' => \stdClass::class, \stdClass::class],
        'aliases' => ['entry' => 'intermediate', 'intermediate' => \stdClass::class],
        'factories' => ['product' => new FactoryDefinition($factory), 'copy' => $reference],
        'delegators' => ['product' => $delegator],
    ], ['default' => 'product']))->toBe([
        'factories' => ['product' => $factory, 'copy' => $reference],
        'invokables' => [\stdClass::class],
        'aliases' => [
            'default' => 'product',
            'entry' => 'intermediate',
            'intermediate' => \stdClass::class,
        ],
        'delegators' => ['product' => [$delegator]],
    ]);
});

test('normalization rejects malformed extension pairs before resolving services', function (mixed $specification): void {
    expect(fn() => DependencyConfiguration::normalize([
        'parameter_resolvers' => [100 => $specification],
    ]))->toThrow(InvalidConfigurationException::class, 'Parameter resolver specification');
})->with([
    'empty id' => [['', 'resolve']],
    'empty method' => [['resolver', '']],
    'non-string method' => [['resolver', 12]],
    'non-string receiver' => [[12, 'resolve']],
    'non-callable object' => [[new \stdClass(), 'resolve']],
    'associative pair' => [['receiver' => 'resolver', 'method' => 'resolve']],
    'empty specification' => [''],
]);

test('normalization rejects incompatible raw and wrapped factory signatures', function (bool $wrapped): void {
    $factory = static fn(string $container): string => $container;
    expect(fn() => DependencyConfiguration::normalize([
        'factories' => ['broken' => $wrapped ? new FactoryDefinition($factory) : $factory],
    ]))->toThrow(
        InvalidConfigurationException::class,
        'Factory "broken" parameter #1 ($container) type "string" is incompatible with runtime argument Componenta\\Config\\ContainerValue.',
    );
})->with([true, false]);

test('normalization rejects cycles formed by combining default and configured aliases', function (): void {
    expect(fn() => DependencyConfiguration::normalize(
        ['aliases' => ['first' => 'second']],
        ['second' => 'first'],
    ))->toThrow(CircularDependencyException::class);
});

test('delegator normalization preserves deferred service methods and callable object methods', function (): void {
    $object = new NormalizationDelegator();
    expect(DependencyConfiguration::normalizeDelegatorSpecification(['future-service', 'decorate'], 'entry'))
        ->toBe(['future-service', 'decorate'])
        ->and(DependencyConfiguration::normalizeDelegatorSpecification([$object, 'decorate'], 'entry'))
        ->toBe([$object, 'decorate']);
});

test('delegator normalization rejects malformed and noncallable method pairs', function (mixed $pair): void {
    expect(fn() => DependencyConfiguration::normalizeDelegatorSpecification($pair, 'entry'))
        ->toThrow(InvalidConfigurationException::class, 'Invalid delegator for "entry": array.');
})->with([
    'missing method' => [[new NormalizationDelegator(), 'missing']],
    'integer receiver' => [[1, 'decorate']],
    'integer method' => [['service', 1]],
    'empty receiver' => [['', 'decorate']],
    'empty method' => [['service', '']],
    'wrong keys' => [[1 => 'service', 2 => 'decorate']],
]);
