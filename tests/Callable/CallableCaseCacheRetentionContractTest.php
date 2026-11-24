<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableCaseCacheRetentionContract;

use Componenta\DI\CallableResolver;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidCallableException;
use Psr\Container\ContainerInterface;

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
            if ($result !== $variant) {
                throw new \LogicException('The native callable must return the current integer argument.');
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

final class UnavailableCaseVariantCallable
{
    public function caseVariantInstanceMethod(): void {}
}

final class MissingCaseVariantServices implements ContainerInterface
{
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): mixed
    {
        throw new \LogicException('No service is available.');
    }
}

test('rejected case variants of one method do not retain duplicate native classifications', function (string $form): void {
    $baseName = $form === 'method' ? 'casevariantinstancemethod' : 'unavailablecasevariantcallable';
    $count = 16384;
    $rejectVariants = static function (CallableResolver $resolver) use ($baseName, $form, $count): int {
        $rejected = 0;
        for ($variant = 0; $variant < $count; ++$variant) {
            $name = $baseName;
            for ($bit = 0; $bit < 14; ++$bit) {
                if (($variant >> $bit) & 1) {
                    $name[$bit] = strtoupper($name[$bit]);
                }
            }
            $candidate = $form === 'method'
                ? [UnavailableCaseVariantCallable::class, $name]
                : [__NAMESPACE__ . '\\' . $name, 'caseVariantInstanceMethod'];
            try {
                $resolver->resolve($candidate);
            } catch (InvalidCallableException) {
                ++$rejected;
            }
        }
        return $rejected;
    };

    // Warm PHP's spelling lookups before measuring a fresh resolver's retained
    // metadata. The rejected method is the same declaration in every case.
    $warmup = new CallableResolver(new MissingCaseVariantServices());
    expect($rejectVariants($warmup))->toBe($count);
    unset($warmup);
    gc_collect_cycles();

    $resolver = new CallableResolver(new MissingCaseVariantServices());
    $before = memory_get_usage();
    $rejected = $rejectVariants($resolver);
    gc_collect_cycles();
    $growth = memory_get_usage() - $before;

    // Native classification needs one stable entry. This workload budget leaves
    // room for allocator overhead without retaining thousands of spelling keys;
    // it does not impose a capacity limit on legitimate cached declarations.
    expect($rejected)->toBe($count)
        ->and($growth)->toBeLessThan(1024 * 1024);
})->with(['class', 'method']);

test('native method classification does not fold opaque callable service ids', function (): void {
    $upper = static fn(): string => 'upper';
    $lower = static fn(): string => 'lower';
    $container = container([ConfigKey::SERVICES => [
        'Opaque.Callable' => $upper,
        'opaque.callable' => $lower,
    ]]);

    foreach ([1, 2] as $_attempt) {
        expect($container->resolve('Opaque.Callable'))->toBe($upper)
            ->and($container->resolve('opaque.callable'))->toBe($lower)
            ->and($container->call(['Opaque.Callable', '__invoke']))->toBe('upper')
            ->and($container->call(['opaque.callable', '__invoke']))->toBe('lower');
    }
});
