<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Configuration\DependencyNormalizationContract;

use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

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

test('normalization preserves definitions without collapsing compatible alias chains', function (): void {
    $factory = static fn(): object => new \stdClass();
    $definition = new FactoryDefinition($factory);
    $delegator = static fn(object $entry): object => $entry;
    $reference = new ReferenceDefinition('source');

    expect(DependencyConfiguration::normalize([
        'invokables' => ['entry' => \stdClass::class, \stdClass::class],
        'aliases' => ['entry' => 'intermediate', 'intermediate' => \stdClass::class],
        'factories' => ['product' => $definition, 'copy' => $reference],
        'delegators' => ['product' => $delegator],
    ], ['default' => 'product']))->toBe([
        'factories' => ['product' => $definition, 'copy' => $reference],
        'invokables' => [\stdClass::class],
        'aliases' => [
            'default' => 'product',
            'entry' => 'intermediate',
            'intermediate' => \stdClass::class,
        ],
        'delegators' => ['product' => [$delegator]],
    ]);
});

test('invokable alias compatibility uses the graph at the current position in registration order', function (): void {
    expect(DependencyConfiguration::normalize([
        'aliases' => ['entry' => 'middle'],
        'invokables' => ['middle' => \stdClass::class, 'entry' => \stdClass::class],
    ]))->toBe([
        'invokables' => [\stdClass::class],
        'aliases' => ['entry' => 'middle', 'middle' => \stdClass::class],
    ])->and(fn() => DependencyConfiguration::normalize([
        'aliases' => ['entry' => 'middle'],
        'invokables' => ['entry' => \stdClass::class, 'middle' => \stdClass::class],
    ]))->toThrow(InvalidConfigurationException::class, 'Invokable alias "entry" conflicts with existing target "middle"');
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

test('normalization leaves factory signature validation to container admission', function (bool $wrapped): void {
    $factory = static fn(string $container): string => $container;
    $input = $wrapped ? new FactoryDefinition($factory) : $factory;
    $normalized = DependencyConfiguration::normalize(['factories' => ['broken' => $input]]);

    expect($normalized)->toBe(['factories' => ['broken' => $input]])
        ->and(fn() => (new ContainerBuilder())->addFactory('broken', $input)->build())->toThrow(
            InvalidConfigurationException::class,
            'Factory "broken" parameter #1 ($container) type "string" is incompatible with runtime argument Componenta\\Config\\ContainerValue.',
        );
})->with([true, false]);

test('normalization does not autoload class definitions before semantic admission', function (): void {
    /** @var class-string $class */
    $class = __NAMESPACE__ . '\\NotYetAvailable_' . bin2hex(random_bytes(5));
    $definition = ClassDefinition::create($class);
    $loads = 0;
    $loader = static function (string $requested) use ($class, &$loads): void {
        if ($requested === $class) {
            ++$loads;
        }
    };
    spl_autoload_register($loader);
    try {
        expect(DependencyConfiguration::normalize(['factories' => ['product' => $definition]]))
            ->toBe(['factories' => ['product' => $definition]])
            ->and($loads)->toBe(0)
            ->and(fn() => (new ContainerBuilder())->addDefinition('product', $definition)->build())
            ->toThrow(InvalidConfigurationException::class, 'unknown class')
            ->and($loads)->toBeGreaterThan(0);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('normalization rejects cycles formed by combining default and configured aliases', function (): void {
    expect(fn() => DependencyConfiguration::normalize(
        ['aliases' => ['first' => 'second']],
        ['second' => 'first'],
    ))->toThrow(CircularDependencyException::class);
});

test('normalization rejects an empty default alias before merging it into the dependency graph', function (): void {
    expect(fn() => DependencyConfiguration::normalize([], ['' => 'source']))
        ->toThrow(InvalidConfigurationException::class);
});

test('normalization owns alias routes supplied through defaults', function (bool $changeInput): void {
    $source = 'service';
    $defaults = ['default' => &$source, 'overridden' => &$source];
    $normalized = DependencyConfiguration::normalize([
        'aliases' => ['configured' => 'default', 'overridden' => 'explicit'],
    ], $defaults);

    if ($changeInput) {
        $source = 'changed input';
    } else {
        $copy = $normalized['aliases'] ?? [];
        $copy['default'] = 'changed copy';
    }

    expect($normalized['aliases'] ?? null)->toBe([
        'default' => 'service',
        'overridden' => 'explicit',
        'configured' => 'default',
    ])->and($source)->toBe($changeInput ? 'changed input' : 'service');
})->with(['caller changes its reference' => true, 'caller changes a normalized copy' => false]);

test('delegator normalization preserves deferred service methods and callable object methods', function (): void {
    $object = new NormalizationDelegator();
    expect(DependencyConfiguration::normalize([
        'delegators' => ['entry' => [['future-service', 'decorate'], [$object, 'decorate']]],
    ]))->toBe([
        'delegators' => ['entry' => [['future-service', 'decorate'], [$object, 'decorate']]],
    ]);
});

test('delegator normalization rejects malformed and noncallable method pairs', function (mixed $pair): void {
    expect(fn() => DependencyConfiguration::normalize(['delegators' => ['entry' => [$pair]]]))
        ->toThrow(InvalidConfigurationException::class, 'Invalid delegator for "entry": array.');
})->with([
    'missing method' => [[new NormalizationDelegator(), 'missing']],
    'integer receiver' => [[1, 'decorate']],
    'integer method' => [['service', 1]],
    'empty receiver' => [['', 'decorate']],
    'empty method' => [['service', '']],
    'wrong keys' => [[1 => 'service', 2 => 'decorate']],
]);
