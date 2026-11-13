<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableMagicRetention;

use Componenta\DI\Tests\Support\ContainerBuilder;

final class DynamicTarget
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public function __call(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }

    public function declared(string $value = 'default'): string
    {
        return $value;
    }
}

/** @param array<array-key,mixed> $arguments */
function nativeInvocation(mixed $callable, array $arguments): mixed
{
    if (!is_callable($callable)) {
        throw new \LogicException('The magic fixture must remain a native callable.');
    }
    return call_user_func_array($callable, $arguments);
}

test('magic invocation does not retain an unbounded history of dynamic method names', function (string $form): void {
    $container = (new ContainerBuilder())->build();
    $target = new DynamicTarget();
    $call = static fn(string $method, int $value): mixed => $container->call(match ($form) {
        'instance' => [$target, $method],
        'static array' => [DynamicTarget::class, $method],
        'static string' => DynamicTarget::class . '::' . $method,
        default => throw new \LogicException('Unknown callable form.'),
    }, ['value' => $value]);

    expect($call('warm', 1))->toBe(['warm', ['value' => 1]]);
    gc_collect_cycles();
    $before = memory_get_usage();
    $prefix = bin2hex(random_bytes(8));
    $last = null;
    for ($index = 0; $index < 512; ++$index) {
        $method = str_pad('dynamic_' . $prefix . '_' . $index, 4096, '_');
        $last = $call($method, $index);
    }
    expect($last)->toBe([$method, ['value' => 511]]);
    unset($prefix, $method, $last);
    gc_collect_cycles();
    $retained = memory_get_usage() - $before;

    // This is a workload and memory regression budget, not a production cache
    // limit. Retaining the generated names needs more than two megabytes;
    // normal per-call temporaries and stable metadata fit well below this.
    expect($retained)->toBeLessThan(512 * 1024)
        ->and($container->call([$target, 'declared']))->toBe('default')
        ->and($container->call([$target, 'declared'], ['value' => 'current']))->toBe('current');
})->with(['instance', 'static array', 'static string']);

test('magic method names keep their native spelling and supplied argument binding across repeated calls', function (): void {
    $container = (new ContainerBuilder())->build();
    $target = new DynamicTarget();
    foreach (['', 'missing', 'MISSING', 'two words', "with\0nul"] as $method) {
        for ($round = 0; $round < 2; ++$round) {
            $arguments = [17, 'named' => $round];
            $instance = [$target, $method];
            $static = [DynamicTarget::class, $method];
            expect($container->call($instance, $arguments))->toBe(nativeInvocation($instance, $arguments))
                ->and($container->call($static, $arguments))->toBe(nativeInvocation($static, $arguments));
        }
    }
});
