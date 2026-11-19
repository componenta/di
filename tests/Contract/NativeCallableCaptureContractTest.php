<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\NativeCallableCapture;

use ArgumentCountError;
use Closure;
use Componenta\DI\CallableInvoker;
use Componenta\DI\Exception\InvalidCallableException;
use DomainException;
use ReflectionFunction;
use Throwable;
use TypeError;

class Original
{
    public static function original(): string
    {
        return 'original owner';
    }

    public static function replacement(): string
    {
        return 'original owner, replacement method';
    }
}

final class Replacement
{
    public static function original(): string
    {
        return 'replacement owner';
    }

    public static function replacement(): string
    {
        return 'replacement owner, replacement method';
    }
}

final class Child extends Original {}

/** @return array{result:mixed,loads:int,ownerChanged:bool,method:string} */
function autoloadedCall(bool $native, string $mutation): array
{
    $class = __NAMESPACE__ . '\\Pending_' . bin2hex(random_bytes(5));
    $owner = $class;
    $method = 'original';
    $callable = [&$owner, &$method];
    $loads = 0;
    $loader = static function (string $requested) use ($class, $mutation, &$owner, &$method, &$loads): void {
        if ($requested !== $class) {
            return;
        }
        ++$loads;
        class_alias(Original::class, $class);
        if ($mutation === 'owner' || $mutation === 'both') {
            $owner = Replacement::class;
        } elseif ($mutation === 'invalid owner') {
            $owner = '';
        }
        if ($mutation === 'method' || $mutation === 'both') {
            $method = 'replacement';
        }
    };
    spl_autoload_register($loader);
    try {
        $result = $native
            ? new ReflectionFunction('call_user_func_array')->invoke($callable, [])
            : (new CallableInvoker())->call($callable);
        return ['result' => $result, 'loads' => $loads, 'ownerChanged' => $owner !== $class, 'method' => $method];
    } finally {
        spl_autoload_unregister($loader);
    }
}

test('native invocation resolves referenced receiver and method slots only once', function (string $mutation): void {
    $expected = [
        'result' => in_array($mutation, ['method', 'both'], true) ? 'original owner, replacement method' : 'original owner',
        'loads' => 1,
        'ownerChanged' => $mutation !== 'method',
        'method' => in_array($mutation, ['method', 'both'], true) ? 'replacement' : 'original',
    ];

    expect(autoloadedCall(true, $mutation))->toBe($expected)
        ->and(autoloadedCall(false, $mutation))->toBe($expected);
})->with(['owner', 'method', 'both', 'invalid owner']);

test('native invocation emits a qualified callable diagnostic only once', function (): void {
    $reports = [];
    foreach ([true, false] as $native) {
        $warnings = [];
        set_error_handler(static function (int $severity) use (&$warnings): bool {
            $warnings[] = $severity;
            return true;
        });
        try {
            $callable = [Child::class, Original::class . '::original'];
            $result = $native
                ? new ReflectionFunction('call_user_func_array')->invoke($callable, [])
                : (new CallableInvoker())->call($callable);
            $reports[] = [$result, $warnings];
        } finally {
            restore_error_handler();
        }
    }

    expect($reports[0])->toBe(['original owner', [E_DEPRECATED]])
        ->and($reports[1])->toBe($reports[0]);
});

test('native callable capture preserves the original autoload failure without repeating lookup', function (string $kind): void {
    $class = __NAMESPACE__ . '\\Failed_' . bin2hex(random_bytes(5));
    $cause = null;
    $loads = 0;
    $loader = static function (string $requested) use ($class, $kind, &$cause, &$loads): void {
        if ($requested !== $class) {
            return;
        }
        ++$loads;
        if ($kind === 'nested capture') {
            $missing = __NAMESPACE__ . '\\Missing_' . bin2hex(random_bytes(5));
            if (!is_callable($missing, syntax_only: true)) {
                throw new \LogicException('The missing function must have valid callable syntax.');
            }
            try {
                Closure::fromCallable($missing);
            } catch (TypeError $error) {
                $cause = $error;
                throw $error;
            }
            throw new \LogicException('The nested capture must reject its missing function.');
        }
        $cause = $kind === 'type error' ? new TypeError('Autoload type failure.') : new DomainException('Autoload failure.');
        throw $cause;
    };
    spl_autoload_register($loader);
    try {
        (new CallableInvoker())->call([$class, 'run']);
        \PHPUnit\Framework\Assert::fail('The unresolved callable must fail.');
    } catch (InvalidCallableException $error) {
        expect($cause)->toBeInstanceOf(Throwable::class)
            ->and($error->getPrevious())->toBe($cause)
            ->and($error->callableDescription)->toBe($class . '::run')
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['type error', 'foreign exception', 'nested capture']);

test('native diagnostic handler failures remain the original cause', function (): void {
    $cause = new TypeError('The diagnostic handler failed.');
    $warnings = 0;
    set_error_handler(static function () use ($cause, &$warnings): never {
        ++$warnings;
        throw $cause;
    });
    try {
        (new CallableInvoker())->call([Child::class, Original::class . '::original']);
        \PHPUnit\Framework\Assert::fail('The callable diagnostic must preserve its failure.');
    } catch (InvalidCallableException $error) {
        expect($error->getPrevious())->toBe($cause)
            ->and($warnings)->toBe(1);
    } finally {
        restore_error_handler();
    }
});

test('native diagnostic callback arity failures stay attached to invalid callable exceptions', function (): void {
    new ReflectionFunction('set_error_handler')->invoke([Closure::class, 'fromCallable']);
    try {
        (new CallableInvoker())->call([Child::class, Original::class . '::original']);
        \PHPUnit\Framework\Assert::fail('The diagnostic callback must reject its argument count.');
    } catch (InvalidCallableException $error) {
        expect($error->getPrevious())->toBeInstanceOf(ArgumentCountError::class);
    } finally {
        restore_error_handler();
    }
});

test('captured native invocation preserves reference arguments and application type errors', function (): void {
    $invoker = new CallableInvoker();
    $count = 1;
    $result = $invoker->call(static function (int &$count, string $label): array {
        ++$count;
        return [$count, $label];
    }, [&$count, 'label' => 'named']);

    expect($result)->toBe([2, 'named'])->and($count)->toBe(2)
        ->and(fn() => $invoker->call(static fn(int $value): int => $value, ['invalid']))->toThrow(TypeError::class);

    $cause = new TypeError('The target body failed.');
    try {
        $invoker->call(static fn(): never => throw $cause);
        \PHPUnit\Framework\Assert::fail('The body must throw its original exception.');
    } catch (TypeError $error) {
        expect($error)->toBe($cause);
    }
});
