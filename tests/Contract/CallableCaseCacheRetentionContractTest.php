<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableCaseCacheRetention;

use function Componenta\DI\Tests\Support\container;

final class CaseVariantCallable
{
    public static function value(int $value): int
    {
        return $value;
    }
}

test('case variants of one native static callable do not retain duplicate execution plans', function (): void {
    $container = container();
    $container->call([CaseVariantCallable::class, 'value'], [0]);
    gc_collect_cycles();
    $before = memory_get_usage();

    $total = 0;
    $count = 8192;
    for ($variant = 0; $variant < $count; ++$variant) {
        $class = 'casevariantcallable';
        for ($bit = 0; $bit < 13; ++$bit) {
            if (($variant >> $bit) & 1) {
                $class[$bit] = strtoupper($class[$bit]);
            }
        }
        $result = $container->call([__NAMESPACE__ . '\\' . $class, 'value'], [$variant]);
        if (!is_int($result)) {
            throw new \LogicException('The native callable must return the integer argument.');
        }
        $total += $result;
    }

    unset($class);
    gc_collect_cycles();
    $growth = memory_get_usage() - $before;

    expect($total)->toBe(intdiv($count * ($count - 1), 2));
    // Allow allocator and native lookup overhead, but not thousands of plans
    // for the same PHP declaration. Keep the container alive for this check.
    expect($growth)->toBeLessThan(2 * 1024 * 1024);
});
