<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableCaseCacheRetention;

use Componenta\DI\Container;

use function Componenta\DI\Tests\Support\container;

final class CaseVariantCallable
{
    public static function caseVariantMethod(int $value): int
    {
        return $value;
    }
}

test('case variants of one native static callable do not retain duplicate execution plans', function (string $form): void {
    $baseName = $form === 'array method' ? 'casevariantmethod' : 'casevariantcallable';
    $callable = static fn(string $name): array|string => match ($form) {
        'array class' => [__NAMESPACE__ . '\\' . $name, 'caseVariantMethod'],
        'array method' => [CaseVariantCallable::class, $name],
        'static string' => __NAMESPACE__ . '\\' . $name . '::caseVariantMethod',
        default => throw new \LogicException('Unknown callable form.'),
    };
    $count = 8192;
    $invokeVariants = static function (Container $container) use ($baseName, $callable, $count): int {
        $total = 0;
        for ($variant = 0; $variant < $count; ++$variant) {
            $name = $baseName;
            for ($bit = 0; $bit < 13; ++$bit) {
                if (($variant >> $bit) & 1) {
                    $name[$bit] = strtoupper($name[$bit]);
                }
            }
            $result = $container->call($callable($name), [$variant]);
            if (!is_int($result)) {
                throw new \LogicException('The native callable must return the integer argument.');
            }
            $total += $result;
        }
        return $total;
    };

    // Process-wide lookup state may grow when spellings are first encountered.
    // Warm every spelling on a disposable container before measuring a new one.
    // Reusing the warmed container would conceal duplicate execution plans.
    $expected = intdiv($count * ($count - 1), 2);
    $warmup = container();
    expect($invokeVariants($warmup))->toBe($expected);
    unset($warmup);
    gc_collect_cycles();

    $container = container();
    $container->call($callable($baseName), [0]);
    gc_collect_cycles();
    $before = memory_get_usage();
    $total = $invokeVariants($container);
    gc_collect_cycles();
    $growth = memory_get_usage() - $before;

    expect($total)->toBe($expected);
    // Allow allocator and native lookup overhead, but not thousands of plans
    // for the same PHP declaration. Keep the new container alive for this check.
    expect($growth)->toBeLessThan(2 * 1024 * 1024);
})->with(['array class', 'array method', 'static string']);
