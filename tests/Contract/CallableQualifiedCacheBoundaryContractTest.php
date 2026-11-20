<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableQualifiedCacheBoundary;

use Closure;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;
use LogicException;

class Ancestor
{
    /** @return array{string,string} */
    public static function read(string $ancestor = 'ancestor default'): array
    {
        return ['ancestor', $ancestor];
    }
}

final class Child extends Ancestor {}

final class AliasTarget
{
    /** @return array{string,string} */
    public static function read(string $alias = 'alias default'): array
    {
        return ['alias', $alias];
    }
}

class_alias(AliasTarget::class, Child::class . '::' . Ancestor::class);

function allowingQualifiedCallable(Closure $operation): mixed
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

test('callable plans keep qualified ancestor methods distinct from separator class aliases', function (
    bool $objectOwner,
    bool $aliasFirst,
): void {
    $container = (new ContainerBuilder())->build();
    $qualified = [$objectOwner ? new Child() : Child::class, Ancestor::class . '::read'];
    $aliased = [Child::class . '::' . Ancestor::class, 'read'];
    $cases = [
        'ancestor' => [$qualified, ['ancestor' => 'explicit ancestor'], ['ancestor', 'explicit ancestor']],
        'alias' => [$aliased, ['alias' => 'explicit alias'], ['alias', 'explicit alias']],
    ];

    allowingQualifiedCallable(static function () use ($container, $cases, $aliasFirst): void {
        foreach ($cases as [$callable, $arguments, $expected]) {
            if (!is_callable($callable)) {
                throw new LogicException('Both routes must retain native callability.');
            }
            expect(call_user_func_array($callable, $arguments))->toBe($expected);
        }

        $order = $aliasFirst ? ['alias', 'ancestor', 'alias'] : ['ancestor', 'alias', 'ancestor'];
        foreach ([1, 2] as $_attempt) {
            foreach ($order as $name) {
                [$callable, $arguments, $expected] = $cases[$name];
                expect($container->call($callable, $arguments))->toBe($expected);
            }
        }
    });
})->with(['class receiver' => false, 'object receiver' => true])
    ->with(['qualified method first' => false, 'class alias first' => true]);
