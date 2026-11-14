<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\CallableInvoker;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\InvalidConfigurationException;

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

test('native invoker normalizes autoload failures before invocation begins', function (bool $array): void {
    $class = __NAMESPACE__ . '\\NativeInvokerUnavailable';
    $callable = $array ? [$class, 'run'] : $class . '::run';
    $cause = new \DomainException('Callable autoload failed.');
    $loads = 0;
    $autoload = static function (string $name) use ($class, $cause, &$loads): void {
        if ($name === $class) {
            ++$loads;
            throw $cause;
        }
    };
    spl_autoload_register($autoload);

    try {
        (new CallableInvoker())->call($callable);
        \PHPUnit\Framework\Assert::fail('Callable preparation must reject the autoload failure.');
    } catch (InvalidCallableException $exception) {
        expect($exception->getPrevious())->toBe($cause)
            ->and($exception->callableDescription)->toBe($class . '::run')
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['string' => false, 'array' => true]);

test('native invoker preserves Componenta exceptions from callable preparation', function (): void {
    $class = __NAMESPACE__ . '\\NativeInvokerRejected';
    $cause = new InvalidConfigurationException('Callable class configuration failed.');
    $autoload = static function (string $name) use ($class, $cause): void {
        if ($name === $class) {
            throw $cause;
        }
    };
    spl_autoload_register($autoload);

    try {
        (new CallableInvoker())->call([$class, 'run']);
        \PHPUnit\Framework\Assert::fail('Callable preparation must reject the configuration failure.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception)->toBe($cause);
    } finally {
        spl_autoload_unregister($autoload);
    }
});

test('native invoker leaves application exceptions outside callable preparation', function (): void {
    $cause = new \DomainException('Callable body failed.');
    $callable = static function () use ($cause): never {
        throw $cause;
    };

    try {
        (new CallableInvoker())->call($callable);
        \PHPUnit\Framework\Assert::fail('The application exception must propagate.');
    } catch (\DomainException $exception) {
        expect($exception)->toBe($cause);
    }
});
