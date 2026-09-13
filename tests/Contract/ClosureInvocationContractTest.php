<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClosureInvocation;

use function Componenta\DI\Tests\Support\container;

test('invokes closure methods with their own defaults and named arguments', function (?string $method): void {
    $container = container();
    $first = static fn(string $left = 'first'): string => $left;
    $second = static fn(string $right = 'second'): string => $right;
    $firstCallable = $method === null ? $first : [$first, $method];
    $secondCallable = $method === null ? $second : [$second, $method];

    expect($container->call($firstCallable))->toBe('first')
        ->and($container->call($secondCallable))->toBe('second')
        ->and($container->call($firstCallable, ['left' => 'third']))->toBe('third')
        ->and($container->call($secondCallable, ['right' => 'fourth']))->toBe('fourth');
})->with([null, '__invoke', '__INVOKE']);
