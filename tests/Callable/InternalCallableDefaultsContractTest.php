<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\InternalCallableDefaultsContract;

use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;

use function Componenta\DI\Tests\Support\container;

test('internal callables preserve omission instead of manufacturing a null filter', function (bool $closure): void {
    $callable = $closure ? array_keys(...) : 'array_keys';
    $container = container();
    $values = ['one' => 1, 'nil' => null, 'two' => 2];

    expect($container->call($callable, ['array' => $values]))->toBe(['one', 'nil', 'two'])
        ->and($container->call($callable, ['array' => $values, 'filter_value' => null]))->toBe(['nil'])
        ->and($container->call($callable, [$values, 2, true]))->toBe(['two']);
})->with([false, true]);

test('internal optional arguments after a gap keep their native named binding', function (): void {
    $container = container();
    expect($container->call('array_slice', ['array' => ['a', 'b', 'c'], 'offset' => 1, 'preserve_keys' => true]))
        ->toBe([1 => 'b', 2 => 'c']);
});

test('internal methods and constructors keep native defaults', function (): void {
    $container = container();
    $engine = $container->make(Mt19937::class);
    Assert::assertInstanceOf(Mt19937::class, $engine);
    expect($engine->generate())->toBeString()
        ->and($container->call([$engine, 'generate']))->toBeString();

    $seeded = $container->make(Mt19937::class, ['seed' => 123]);
    Assert::assertInstanceOf(Mt19937::class, $seeded);
    $native = new Mt19937(123);
    expect($seeded->generate())->toBe($native->generate())
        ->and($container->call([$seeded, 'generate']))->toBe($native->generate());
});

test('internal optional non-nullable parameters can use unavailable native defaults', function (): void {
    expect(container()->call('rand'))->toBeInt();
});

test('bootstrap detects later resolvers that would replace omitted native arguments', function (): void {
    expect(fn() => container(['parameter_resolvers' => [
        -100 => static function (Container $container): ParameterResolverInterface {
            $container->call('rand');
            return new class () implements ParameterResolverInterface {
                public function supports(ParameterTarget $target): bool
                {
                    return $target->name === 'min';
                }

                public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
                {
                    return [$target->position, 0];
                }
            };
        },
    ]]))->toThrow(InvalidConfigurationException::class, 'native argument omission');
});

test('custom resolvers can supply an optional internal parameter ahead of native defaults', function (): void {
    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'filter_value';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [$target->position, 2];
        }
    };
    $container = container(['parameter_resolvers' => [500 => $resolver]]);
    expect($container->call('array_keys', [['one' => 1, 'two' => 2]]))->toBe(['two']);
});

test('missing required internal arguments still fail resolution', function (): void {
    expect(fn() => container()->call('strlen'))->toThrow(ResolutionException::class);
});

test('ordinary callable defaults still resolve to their declared value', function (): void {
    $container = container();
    expect($container->call(static fn(?string $value = 'default'): ?string => $value))->toBe('default')
        ->and($container->call(static fn(?string $value = 'default'): ?string => $value, ['value' => null]))->toBeNull();
});


test('internal optional gaps reject positional variadic tails before native invocation', function (): void {
    $pdo = new \PDO('sqlite::memory:');
    $statement = $pdo->query('SELECT 1 AS value');
    if (!$statement instanceof \PDOStatement) {
        throw new \LogicException('Expected the native PDO statement fixture.');
    }

    expect(fn() => container()->call([$statement, 'fetchAll'], ['args' => ['invalid-mode']]))
        ->toThrow(ResolutionException::class, 'Positional variadic arguments cannot follow omitted native arguments.');
})->skip(
    !class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true),
    'PDO SQLite is required to exercise a native optional parameter followed by a variadic tail.',
);
