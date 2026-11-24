<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\MagicClosureInvocationContract;

use Closure;
use DateTimeImmutable;
use WeakReference;

use function Componenta\DI\Tests\Support\container;

/**
 * @method array{string, array<array-key, mixed>} dispatch(mixed ...$arguments)
 * @method static array{string, array<array-key, mixed>} dispatchStatic(mixed ...$arguments)
 */
final class MagicClosureTarget
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return array{string, array<array-key, mixed>}
     */
    public function __call(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return array{string, array<array-key, mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }

    public function privateCallable(): Closure
    {
        return $this->hidden(...);
    }

    private function hidden(string $value): string
    {
        return 'private:' . $value;
    }
}

test('magic closures preserve native positional and named argument behavior', function (bool $static, bool $firstClass): void {
    $target = new MagicClosureTarget();
    $callable = $static
        ? ($firstClass ? MagicClosureTarget::dispatchStatic(...) : Closure::fromCallable([MagicClosureTarget::class, 'dispatchStatic']))
        : ($firstClass ? $target->dispatch(...) : Closure::fromCallable([$target, 'dispatch']));
    $method = $static ? 'dispatchStatic' : 'dispatch';
    $container = container();

    expect($container->call($callable, ['value', 42]))->toBe([$method, ['value', 42]])
        ->and($container->call($callable, ['next']))->toBe([$method, ['next']]);

    $arguments = ['position', 'named' => 42];
    // PHP does not accept named arguments for every native magic closure.
    // Compare with the actual native call instead of assuming a variadic signature.
    try {
        $expected = call_user_func_array($callable, $arguments);
    } catch (\Error $nativeFailure) {
        expect(fn() => $container->call($callable, $arguments))
            ->toThrow($nativeFailure::class, $nativeFailure->getMessage())
            ->and($container->call($callable, ['recovered']))->toBe([$method, ['recovered']]);
        return;
    }

    expect($expected)->toBe([$method, $arguments])
        ->and($container->call($callable, $arguments))->toBe($expected);
})->with(['instance' => false, 'static' => true])
    ->with(['fromCallable' => false, 'first class' => true]);

test('magic closure signature handling does not retain its bound object', function (bool $firstClass): void {
    $container = container();
    $target = new MagicClosureTarget();
    $reference = WeakReference::create($target);
    $callable = $firstClass ? $target->dispatch(...) : Closure::fromCallable([$target, 'dispatch']);

    expect($container->call($callable, [42]))->toBe(['dispatch', [42]]);

    unset($callable, $target);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
})->with(['fromCallable' => false, 'first class' => true]);

test('magic closures for an inaccessible declared method preserve native arguments', function (bool $firstClass): void {
    $target = new MagicClosureTarget();
    $method = 'hidden';
    $candidate = [$target, $method];
    $callable = $firstClass ? $target->{$method}(...) : Closure::fromCallable($candidate);
    $container = container();

    expect($container->call($callable, ['first', 42]))->toBe(['hidden', ['first', 42]])
        ->and($container->call($callable, ['next']))->toBe(['hidden', ['next']]);
})->with(['fromCallable' => false, 'first class' => true]);

test('real closure signatures still resolve named parameters including internal and private methods', function (): void {
    $container = container();
    $target = new MagicClosureTarget();
    $date = new DateTimeImmutable('2026-01-02T00:00:00+00:00');

    expect($container->call($target->privateCallable(), ['value' => 'resolved']))->toBe('private:resolved')
        ->and($container->call(strlen(...), ['string' => 'text']))->toBe(4)
        ->and($container->call($date->format(...), ['format' => 'Y-m-d']))->toBe('2026-01-02')
        ->and($container->call($date->format(...), ['format' => 'Y', 'unused' => 'ignored']))->toBe('2026')
        ->and($container->call(static fn(): int => func_num_args(), [42]))->toBe(0);
});
