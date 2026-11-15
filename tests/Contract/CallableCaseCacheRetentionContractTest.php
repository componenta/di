<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableCaseCacheRetention;

use function Componenta\DI\Tests\Support\container;

final class CaseVariantCallable
{
    public static function caseVariantMethod(int $value): int
    {
        return $value;
    }
}

test('case variants of one native static callable do not retain duplicate execution plans', function (string $form): void {
    $container = container();
    $baseName = $form === 'array method' ? 'casevariantmethod' : 'casevariantcallable';
    $callable = static fn(string $name): array|string => match ($form) {
        'array class' => [__NAMESPACE__ . '\\' . $name, 'caseVariantMethod'],
        'array method' => [CaseVariantCallable::class, $name],
        'static string' => __NAMESPACE__ . '\\' . $name . '::caseVariantMethod',
        default => throw new \LogicException('Unknown callable form.'),
    };
    $container->call($callable($baseName), [0]);
    gc_collect_cycles();
    $before = memory_get_usage();

    $total = 0;
    $count = 8192;
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

    unset($name);
    gc_collect_cycles();
    $growth = memory_get_usage() - $before;

    expect($total)->toBe(intdiv($count * ($count - 1), 2));
    // Allow allocator and native lookup overhead, but not thousands of plans
    // for the same PHP declaration. Keep the container alive for this check.
    expect($growth)->toBeLessThan(2 * 1024 * 1024);
})->with(['array class', 'array method', 'static string']);
