<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\NativeCallableInvokerContract;

use Closure;
use Componenta\DI\CallableInvoker;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Error;
use LogicException;
use WeakReference;

/** @method array{string,class-string,string,array<array-key,mixed>} Unused(mixed ...$arguments) */
class NativeMagicOwner
{
    public function declared(string $value): string
    {
        return 'declared:' . $value;
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public function __call(string $method, array $arguments): array
    {
        return ['instance', static::class, $method, $arguments];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return ['static', static::class, $method, $arguments];
    }
}

final class NativeMagicChild extends NativeMagicOwner {}

final class NativeMagicReplacement extends NativeMagicOwner
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return ['replacement', static::class, $method, $arguments];
    }
}

/** @method bool releaseMagic(?object &$value) */
final class ArgumentLifetimeProbe
{
    /** @param-out null $value */
    public function release(?object &$value): bool
    {
        $reference = WeakReference::create($value ?? throw new LogicException('Expected a live argument.'));
        $value = null;
        return $reference->get() === null;
    }

    /** @param array{object|null} $arguments */
    public function __call(string $method, array $arguments): bool
    {
        return $this->release($arguments[0]);
    }
}

/** @method array<array-key,mixed> append(mixed ...$arguments) */
final class AppendingMagicArguments
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array<array-key,mixed>
     */
    public function __call(string $method, array $arguments): array
    {
        $arguments[] = 'appended';
        return $arguments;
    }
}

test('native callable invoker preserves named variadic arguments', function (): void {
    $result = (new CallableInvoker())->call(
        static fn(string $prefix, string ...$parts): array => [$prefix, $parts],
        ['prefix' => 'start', 'first' => 'one', 'second' => 'two'],
    );

    expect($result)->toBe(['start', ['first' => 'one', 'second' => 'two']]);
});

test('native invoker binds named arguments for an invokable object', function (): void {
    $callback = new class () {
        public function __invoke(string $value): string
        {
            return 'invoked:' . $value;
        }
    };

    expect(new CallableInvoker()->call($callback, ['value' => 'named']))->toBe('invoked:named');
});

test('native invoker selects the declared method when its receiver also has a magic hook', function (): void {
    $callback = [new NativeMagicChild(), 'declared'];

    expect(new CallableInvoker()->call($callback, ['value' => 'named']))->toBe('declared:named');
});

test('native invoker releases the original positional argument when a method replaces its reference', function (bool $magic): void {
    $receiver = new ArgumentLifetimeProbe();
    $target = $magic ? [$receiver, 'releaseMagic'] : [$receiver, 'release'];
    $value = new \stdClass();
    $native = \call_user_func_array($target, [&$value]);

    $value = new \stdClass();
    $actual = new CallableInvoker()->call($target, [&$value]);

    expect($native)->toBeTrue()
        ->and($actual)->toBe($native)
        ->and($value)->toBeNull();
})->with(['ordinary method' => false, 'magic method' => true]);

test('native invoker gives a magic method a fresh positional append index with references intact', function (): void {
    $target = [new AppendingMagicArguments(), 'append'];
    $value = 'before';
    $arguments = [&$value, 100 => 'removed'];
    unset($arguments[100]);

    $native = \call_user_func_array($target, $arguments);
    $actual = new CallableInvoker()->call($target, $arguments);
    $value = 'after';

    expect($native)->toBe(['after', 'appended'])
        ->and($actual)->toBe($native);
});

test('native invoker preserves arguments and reference cells for raw magic callables', function (callable $target, string $kind): void {
    $named = 'original';
    $parameters = [7 => 'positional', 'named' => &$named];

    $native = call_user_func_array($target, $parameters);
    $resolved = new CallableInvoker()->call($target, $parameters);

    expect($native)->toBe([$kind, NativeMagicChild::class, 'Unused', ['positional', 'named' => 'original']])
        ->and($resolved)->toBe($native);

    $named = 'changed';
    expect($resolved)->toBe([$kind, NativeMagicChild::class, 'Unused', ['positional', 'named' => 'changed']]);
})->with([
    'instance pair' => [[new NativeMagicChild(), 'Unused'], 'instance'],
    'static pair' => [[NativeMagicChild::class, 'Unused'], 'static'],
    'static string' => [NativeMagicChild::class . '::Unused', 'static'],
]);

test('native magic binding retains its PHP-selected route after an autoloader rebinds the source', function (): void {
    $invoke = static function (bool $native): array {
        $class = __NAMESPACE__ . '\\LateMagic_' . bin2hex(random_bytes(5));
        $owner = $class;
        $method = 'beforeLoad';
        /** @var array{string,string}&callable(string $label):array<array-key,mixed> $target */
        $target = [&$owner, &$method];
        $loads = 0;
        $loader = static function (string $name) use ($class, &$owner, &$method, &$loads): void {
            if ($name === $class) {
                ++$loads;
                class_alias(NativeMagicChild::class, $class);
                $owner = NativeMagicReplacement::class;
                $method = 'afterLoad';
            }
        };
        spl_autoload_register($loader);
        try {
            $result = $native
                ? \call_user_func_array($target, ['label' => 'native'])
                : new CallableInvoker()->call($target, ['label' => 'native']);
            return [$result, $owner, $method, $loads];
        } finally {
            spl_autoload_unregister($loader);
        }
    };

    $native = $invoke(true);

    expect($native)->toBe([
        ['static', NativeMagicChild::class, 'afterLoad', ['label' => 'native']],
        NativeMagicReplacement::class,
        'afterLoad',
        1,
    ])->and($invoke(false))->toBe($native);
});

test('a caller-supplied magic closure keeps the behavior of its native capture', function (): void {
    $target = Closure::fromCallable([new NativeMagicChild(), 'Unused']);
    $parameters = ['named' => 'value'];
    $invoker = new CallableInvoker();

    try {
        $native = call_user_func_array($target, $parameters);
    } catch (Error $failure) {
        expect(fn() => $invoker->call($target, $parameters))->toThrow($failure::class, $failure->getMessage());
        return;
    }

    expect($invoker->call($target, $parameters))->toBe($native);
});

test('a qualification diagnostic can rebind the source without changing the selected magic hook', function (bool $named): void {
    $qualified = NativeMagicOwner::class . '::Unused';
    $invoke = static function (bool $native, string $qualified) use ($named): array {
        $owner = new NativeMagicReplacement();
        $method = $qualified;
        /** @var array{NativeMagicReplacement,string}&callable $target */
        $target = [&$owner, &$method];
        $value = 'before';
        $arguments = $named ? [3 => 'positional', 'named' => &$value] : [3 => &$value];
        $warnings = [];
        set_error_handler(static function (int $severity) use (&$warnings, &$owner, &$method, &$value): bool {
            $warnings[] = $severity;
            $owner = new NativeMagicChild();
            $method = 'Unused';
            $value = 'after diagnostic';
            return true;
        });
        try {
            $result = $native
                ? \call_user_func_array($target, $arguments)
                : new CallableInvoker()->call($target, $arguments);
            if (!is_array($result)) {
                throw new LogicException('Expected the selected native magic hook.');
            }
            return [$result[0], $result[1], $result[3], $warnings, $owner::class, $method];
        } finally {
            restore_error_handler();
        }
    };

    $native = $invoke(true, $qualified);

    $arguments = $named ? ['positional', 'named' => 'after diagnostic'] : ['after diagnostic'];
    expect($native)->toBe(['static', NativeMagicReplacement::class, $arguments, [E_DEPRECATED], NativeMagicChild::class, 'Unused'])
        ->and($invoke(false, $qualified))->toBe($native);
})->with(['sparse positional' => false, 'named' => true]);

test('native invoker reports the rejected callable shape', function (
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

test('a rejected native callable is released while its diagnostic remains available', function (bool $array): void {
    $previous = ini_set('zend.exception_ignore_args', '1');
    if ($previous === false) {
        throw new LogicException('The test must isolate DI metadata from native exception trace arguments.');
    }
    $invoker = new CallableInvoker();
    $owner = new \stdClass();
    $reference = WeakReference::create($owner);

    try {
        try {
            $invoker->call($array ? [$owner, 'missing'] : $owner);
            \PHPUnit\Framework\Assert::fail('The rejected callable must produce a diagnostic.');
        } catch (InvalidCallableException $error) {
            unset($owner);
            gc_collect_cycles();

            expect($reference->get())->toBeNull()
                ->and($error->callableDescription)->toBe($array ? 'stdClass::missing' : 'object stdClass');
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
})->with(['rejected object' => false, 'rejected object method' => true]);

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
