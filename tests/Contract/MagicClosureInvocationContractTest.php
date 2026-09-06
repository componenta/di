<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

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

test('magic closures receive the supplied positional arguments', function (bool $static, bool $firstClass): void {
    $target = new MagicClosureTarget();
    $callable = $static
        ? ($firstClass ? MagicClosureTarget::dispatchStatic(...) : Closure::fromCallable([MagicClosureTarget::class, 'dispatchStatic']))
        : ($firstClass ? $target->dispatch(...) : Closure::fromCallable([$target, 'dispatch']));
    $method = $static ? 'dispatchStatic' : 'dispatch';
    $container = container();

    expect($container->call($callable, ['value', 42]))->toBe([$method, ['value', 42]])
        ->and($container->call($callable, ['next']))->toBe([$method, ['next']]);
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

test('real closure signatures still resolve named parameters including internal and private methods', function (): void {
    $container = container();
    $target = new MagicClosureTarget();
    $date = new DateTimeImmutable('2026-01-02T00:00:00+00:00');

    expect($container->call($target->privateCallable(), ['value' => 'resolved']))->toBe('private:resolved')
        ->and($container->call(strlen(...), ['string' => 'text']))->toBe(4)
        ->and($container->call($date->format(...), ['format' => 'Y-m-d']))->toBe('2026-01-02')
        ->and($container->call(static fn(): int => func_num_args(), [42]))->toBe(0);
});
