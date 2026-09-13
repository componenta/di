<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\VariadicResolution;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class Dependency {}

class Product
{
    /** @var array<array-key,string> */
    public array $parts;
    public function __construct(public Dependency $dependency, public string $prefix = 'default', string ...$parts)
    {
        $this->parts = $parts;
    }
}

#[Lazy]
final class LazyProduct extends Product {}

#[Proxy]
final class ProxyProduct extends Product {}

#[SetUp('configure', ['parts' => ['setup-one', 'setup-two']])]
final class ConfiguredProduct
{
    /** @var array<array-key,string> */
    public array $parts = [];
    public function configure(string ...$parts): void
    {
        $this->parts = $parts;
    }
}

test('call resolves variadic input without forwarding unrelated context', function (array $params, array $expected): void {
    $di = (new ContainerBuilder())->build();
    $params[ServerRequestInterface::class] = new ServerRequest('GET', '/');
    $params[Dependency::class] = new Dependency();
    $params['unrelated'] = 'context';
    $params["\0componenta.di.test"] = 'internal';

    expect($di->call(static fn(string ...$parts): array => $parts, $params))->toBe($expected);
})->with([
    'empty' => [[], []],
    'positional' => [['one', 'two'], ['one', 'two']],
    'positions are ordered by index' => [[2 => 'two', 1 => 'one'], ['one', 'two']],
    'named collection' => [['parts' => ['one', 'two']], ['one', 'two']],
    'named wins' => [[0 => 'ignored', 'parts' => ['chosen']], ['chosen']],
    'explicit empty wins' => [[0 => 'ignored', 'parts' => []], []],
    'named elements' => [['parts' => ['left' => 'one', 'right' => 'two']], ['left' => 'one', 'right' => 'two']],
]);

test('constructor variadics preserve preceding autowiring and defaults', function (string $class): void {
    if (!is_a($class, Product::class, true)) {
        throw new \LogicException('Expected a product class.');
    }
    $dependency = new Dependency();
    $di = (new ContainerBuilder())->addService(Dependency::class, $dependency)->build();

    $entry = $di->make($class, [2 => 'one', 3 => 'two', Dependency::class => $dependency]);
    expect($entry)->toBeInstanceOf(Product::class);
    if (!$entry instanceof Product) {
        throw new \LogicException('Expected a product.');
    }
    expect([$entry->dependency, $entry->prefix, $entry->parts])->toBe([$dependency, 'default', ['one', 'two']]);
})->with([Product::class, LazyProduct::class, ProxyProduct::class]);

test('SetUp receives its explicit variadic collection', function (): void {
    $di = (new ContainerBuilder())->build();
    expect($di->make(ConfiguredProduct::class)->parts)->toBe(['setup-one', 'setup-two']);
});

test('array variadics preserve each array argument', function (): void {
    $di = (new ContainerBuilder())->build();
    $callable = static fn(array ...$items): array => $items;

    expect($di->call($callable, [['a', 'b']]))->toBe([['a', 'b']])
        ->and($di->call($callable, ['items' => [['a'], ['b']]]))->toBe([['a'], ['b']]);
});

test('typed and nullable variadics default to empty without implicit dependency lookup', function (): void {
    $calls = 0;
    $di = (new ContainerBuilder())->addFactory(Dependency::class, static function () use (&$calls): Dependency {
        ++$calls;
        return new Dependency();
    })->build();
    $explicit = new Dependency();

    expect($di->call(static fn(Dependency ...$items): array => $items, [Dependency::class => $explicit]))->toBe([])
        ->and($di->call(static fn(?Dependency ...$items): array => $items))->toBe([])
        ->and($di->call(static fn(?Dependency ...$items): array => $items, ['items' => [null, $explicit]]))->toBe([null, $explicit])
        ->and($calls)->toBe(0);
});

test('invalid variadic inputs fail before the callable body', function (array $params, string $reason): void {
    $called = false;
    $di = (new ContainerBuilder())->build();
    $callable = static function (string $prefix = 'default', string ...$parts) use (&$called): void {
        $called = true;
    };

    expect(fn() => $di->call($callable, $params))->toThrow(ResolutionException::class, $reason)
        ->and($called)->toBeFalse();
})->with([
    'scalar collection' => [['parts' => 'one'], 'must be an array of arguments'],
    'null collection' => [['parts' => null], 'must be an array of arguments'],
    'invalid named element' => [['parts' => ['one', 2]], 'does not satisfy the declared element type'],
    'invalid positional element' => [[1 => 'one', 2 => 2], 'does not satisfy the declared element type'],
    'named element overwrites fixed parameter' => [['parts' => ['prefix' => 'overwritten']], 'would overwrite a preceding parameter'],
    'positional element after named element' => [['parts' => ['named' => 'one', 0 => 'two']], 'must precede named arguments'],
]);

test('attributes provide and transform a variadic collection once per invocation', function (): void {
    $caster = new class () implements CasterInterface {
        public int $calls = 0;
        public string $name { get => 'upper-parts'; }
        public function cast(mixed $value): mixed
        {
            ++$this->calls;
            if (!is_array($value)) {
                throw new \LogicException('Expected a collection.');
            }
            $result = [];
            foreach ($value as $key => $part) {
                if (!is_string($part)) {
                    throw new \LogicException('Expected a string element.');
                }
                $result[$key] = strtoupper($part);
            }
            return $result;
        }
    };
    $provider = new class ($caster) implements CasterProviderInterface {
        public function __construct(private CasterInterface $caster) {}
        public function provide(string $name): ?CasterInterface
        {
            return $name === $this->caster->name ? $this->caster : null;
        }
    };
    $di = ContainerBuilder::configure(new Config(['parts' => ['config']], new Environment([])))
        ->addService(CasterProviderInterface::class, $provider)->build();
    $callable = static fn(#[ConfigAttribute('parts'), Cast('upper-parts')] string ...$parts): array => $parts;

    expect($di->call($callable))->toBe(['CONFIG'])
        ->and($di->call($callable, ['one', 'two']))->toBe(['ONE', 'TWO'])
        ->and($di->call($callable, ['parts' => []]))->toBe([])
        ->and($di->call(static fn(#[Cast('upper-parts')] string ...$parts): array => $parts))->toBe([])
        ->and($caster->calls)->toBe(4);
});

test('custom parameter resolvers can provide a variadic collection', function (): void {
    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->variadic;
        }
        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [$target->position, ['custom-one', 'custom-two']];
        }
    };
    $di = (new ContainerBuilder())->addParameterResolver($resolver, 1500)->build();

    expect($di->call(static fn(string ...$parts): array => $parts))->toBe(['custom-one', 'custom-two']);
});

test('by-reference variadic parameters remain unsupported', function (): void {
    $di = (new ContainerBuilder())->build();
    expect(fn() => $di->call(static function (string &...$parts): void {}, ['one']))->toThrow(ResolutionException::class);
});

test('custom variadic resolvers can derive arguments from already resolved fixed parameters', function (): void {
    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->variadic;
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [$target->position, array_values($context->resolved)];
        }
    };
    $di = (new ContainerBuilder())->addParameterResolver($resolver, 1500)->build();

    expect($di->call(
        static fn(int $first, int $second, int ...$items): array => $items,
        ['second' => 20, 'first' => 10, 'unrelated' => 30],
    ))->toBe([10, 20]);
});
