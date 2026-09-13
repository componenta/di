<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\CallableInvoker;
use Componenta\DI\Exception\InvalidCallableException;

test('native callable invoker preserves named variadic arguments', function (): void {
    $result = (new CallableInvoker())->call(
        static fn(string $prefix, string ...$parts): array => [$prefix, $parts],
        ['prefix' => 'start', 'first' => 'one', 'second' => 'two'],
    );

    expect($result)->toBe(['start', ['first' => 'one', 'second' => 'two']]);
});

test('native invoker reports the rejected callable shape without retaining the input', function (
    mixed $value,
    string $type,
    string $description,
): void {
    try {
        (new CallableInvoker())->call($value);
        \PHPUnit\Framework\Assert::fail('Invalid callable input must be rejected.');
    } catch (InvalidCallableException $exception) {
        expect($exception->callableType)->toBe($type)
            ->and($exception->callableDescription)->toBe($description)
            ->and($exception->getMessage())->toBe('Cannot convert value of type "' . $type . '" to a callable.')
            ->and($exception->getPrevious())->toBeNull();
    }
})->with([
    'integer' => [12, 'int', 'int'],
    'missing function' => ['no_such_native_function', 'string', 'no_such_native_function'],
    'plain object' => [new \stdClass(), 'stdClass', 'object stdClass'],
    'object method' => [[new \stdClass(), 'missing'], 'array', 'stdClass::missing'],
    'class method' => [[\stdClass::class, 'missing'], 'array', 'stdClass::missing'],
    'integer receiver' => [[12, 'run'], 'array', 'int::run'],
    'invalid method' => [[\stdClass::class, 12], 'array', 'array'],
    'sparse pair' => [[1 => \stdClass::class, 2 => 'missing'], 'array', 'array'],
    'one item' => [[\stdClass::class], 'array', 'array'],
    'three items' => [[\stdClass::class, 'missing', 'extra'], 'array', 'array'],
]);
