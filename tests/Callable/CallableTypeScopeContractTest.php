<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableTypeScopeContract;

use Closure;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\TypeHints;

use function Componenta\DI\Tests\Support\container;
use function Componenta\DI\Tests\Support\fixtureInstance;

class Scope
{
    private static function hidden(): string
    {
        return 'private';
    }

    protected static function inherited(): string
    {
        return 'protected';
    }

    /** @return Closure():string */
    public static function privateClosure(): Closure
    {
        return self::hidden(...);
    }

    /** @param callable():string $value */
    public static function accept(callable $value): string
    {
        return $value();
    }

    /** @param callable():string $value */
    public function instance(callable $value): string
    {
        return $value();
    }

    /** @param callable():string|int $value */
    public static function union(callable|int $value): string|int
    {
        return is_int($value) ? $value : $value();
    }

    /**
     * @param callable():string ...$values
     * @return array<array-key,string>
     */
    public static function variadic(callable ...$values): array
    {
        return array_map(static fn(callable $value): string => $value(), $values);
    }

    /** @return Closure(callable():string):string */
    public static function closure(): Closure
    {
        return static function (callable $value): string {
            $result = $value();
            if (!is_string($result)) {
                throw new \LogicException('The scope fixture callback must return a string.');
            }
            return $result;
        };
    }
}

final class ChildScope extends Scope
{
    private static function child(): string
    {
        return 'child';
    }

    public static function childClosure(): Closure
    {
        return self::child(...);
    }
}

final class OtherScope
{
    private static function hidden(): string
    {
        return 'rebound';
    }

    public static function closure(): Closure
    {
        return self::hidden(...);
    }
}

final class ConstructorScope
{
    public readonly string $result;

    /** @param callable():string $value */
    public function __construct(callable $value)
    {
        $this->result = $value();
    }

    private static function hidden(): string
    {
        return 'constructor';
    }

    /** @return callable():string */
    public static function argument(): callable
    {
        return [self::class, 'hidden'];
    }
}

test('callable parameters use their declaring scope on the first and subsequent calls', function (): void {
    $container = container();
    $private = [Scope::class, 'hidden'];
    $protected = [ChildScope::class, 'inherited'];
    $targets = [
        [Scope::class, 'accept'],
        [new Scope(), 'instance'],
        [ChildScope::class, 'accept'],
        Scope::closure(),
        Scope::accept(...),
        [Scope::class, 'union'],
    ];

    foreach ($targets as $target) {
        foreach ([1, 2] as $_attempt) {
            expect(call_user_func_array($target, [$private]))->toBe('private')
                ->and($container->call($target, ['value' => $private]))->toBe('private')
                ->and(call_user_func_array($target, [$protected]))->toBe('protected')
                ->and($container->call($target, [0 => $protected]))->toBe('protected');
        }
        expect($container->call($target, ['value' => Scope::privateClosure()]))->toBe('private');
    }

    $values = [$private, 'named' => $protected];
    foreach ([1, 2] as $_attempt) {
        expect(Scope::variadic(...$values))->toBe(['private', 'named' => 'protected'])
            ->and($container->call([Scope::class, 'variadic'], ['values' => $values]))
            ->toBe(['private', 'named' => 'protected']);
    }
});

test('constructor callable parameters retain native private visibility', function (): void {
    $container = container();
    $value = ConstructorScope::argument();

    expect((new ConstructorScope($value))->result)->toBe('constructor');
    foreach ([1, 2] as $_attempt) {
        expect(fixtureInstance($container->make(ConstructorScope::class, ['value' => $value]), ConstructorScope::class)->result)->toBe('constructor');
    }
});

test('rebound callable parameters use the new scope and reject the previous one', function (): void {
    $target = Scope::closure()->bindTo(null, OtherScope::class);
    if ($target === null) {
        throw new \LogicException('The anonymous fixture must support rebinding.');
    }
    $container = container();
    $value = [OtherScope::class, 'hidden'];

    expect(call_user_func_array($target, [$value]))->toBe('rebound')
        ->and($container->call($target, ['value' => $value]))->toBe('rebound')
        ->and(fn() => call_user_func_array($target, [[Scope::class, 'hidden']]))->toThrow(\TypeError::class)
        ->and(fn() => $container->call($target, ['value' => [Scope::class, 'hidden']]))->toThrow(ResolutionException::class);
});

test('callable checks do not inherit private access from child or DI implementation scopes', function (): void {
    $container = container();
    $global = static fn(callable $value): mixed => $value;
    $cases = [
        [[ChildScope::class, 'accept'], [ChildScope::class, 'child']],
        [$global, [Scope::class, 'hidden']],
        [$global, [TypeHints::class, 'resolveClassName']],
    ];

    foreach ($cases as [$target, $value]) {
        expect(fn() => call_user_func_array($target, [$value]))->toThrow(\TypeError::class)
            ->and(fn() => $container->call($target, ['value' => $value]))->toThrow(ResolutionException::class);
    }
});

test('internal callable parameters accept public callbacks without an internal class binding', function (): void {
    $container = container();
    $values = new \ArrayObject(['b' => 2, 'a' => 1]);
    $compare = static fn(int $left, int $right): int => $left <=> $right;

    expect($container->call([$values, 'uasort'], ['callback' => $compare]))->toBeTrue()
        ->and($values->getArrayCopy())->toBe(['a' => 1, 'b' => 2])
        ->and($container->call('array_filter', ['array' => ['', 'value'], 'callback' => 'strlen']))->toBe([1 => 'value']);
});
