<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\QualifiedNativeMagicInvocationContract;

use Componenta\DI\CallableInvoker;
use Componenta\DI\Exception\InvalidCallableException;
use DomainException;
use Error;
use TypeError;

class ParentHook
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public function __call(string $name, array $arguments): array
    {
        return ['parent instance', static::class, $name, $arguments];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $name, array $arguments): array
    {
        if (($arguments['failure'] ?? null) instanceof TypeError) {
            throw $arguments['failure'];
        }
        return ['parent static', static::class, $name, $arguments];
    }
}

final class ChildHook extends ParentHook
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $name, array $arguments): array
    {
        return ['child static', static::class, $name, $arguments];
    }

    public static function shadowed(string $named): string
    {
        return 'concrete:' . $named;
    }
}

/**
 * @param array{object|class-string,string} $target
 * @param array<array-key,mixed> $arguments
 * @return array{mixed,list<int>}
 */
function observe(array $target, array $arguments, bool $native): array
{
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics): bool {
        $diagnostics[] = $severity;
        return true;
    });
    try {
        /** @var array{object|class-string,string}&callable(mixed ...$arguments):mixed $target */
        $result = $native
            ? \call_user_func_array($target, $arguments)
            : new CallableInvoker()->call($target, $arguments);
        return [$result, $diagnostics];
    } finally {
        restore_error_handler();
    }
}

test('qualified magic calls preserve the selected declaration and native argument references', function (
    bool $object,
    bool $ancestor,
    string $hook,
    string $called,
    string $shape,
): void {
    $method = $ancestor ? 'shadowed' : 'missing';
    $owner = $ancestor ? ParentHook::class : ChildHook::class;
    $target = [$object ? new ChildHook() : ChildHook::class, $owner . '::' . $method];
    $value = 'before';
    $arguments = match ($shape) {
        'named' => [7 => 'position', 'named' => &$value],
        'sparse' => [7 => &$value],
        default => [&$value],
    };

    $native = observe($target, $arguments, native: true);
    $actual = observe($target, $arguments, native: false);
    expect($actual)->toBe($native);

    $value = 'after';
    expect($actual)->toBe([
        [$hook, $called, $method, $shape === 'named' ? ['position', 'named' => 'after'] : ['after']],
        [E_DEPRECATED],
    ]);
})->with([
    'ancestor with object receiver' => [true, true, 'parent static', ChildHook::class],
    'ancestor with class receiver' => [false, true, 'parent static', ParentHook::class],
    'same class with object receiver' => [true, false, 'parent instance', ChildHook::class],
    'same class with class receiver' => [false, false, 'child static', ChildHook::class],
])->with(['named', 'sparse', 'dense']);

test('qualified magic does not accept positional arguments after a named argument', function (): void {
    $target = [new ChildHook(), ParentHook::class . '::shadowed'];
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics): bool {
        $diagnostics[] = $severity;
        return true;
    });
    try {
        expect(fn() => new CallableInvoker()->call($target, ['named' => 'first', 3 => 'second']))
            ->toThrow(Error::class, 'Cannot use positional argument after named argument')
            ->and($diagnostics)->toBe([E_DEPRECATED]);
    } finally {
        restore_error_handler();
    }
});

test('a qualified magic body failure keeps its original identity', function (): void {
    $target = [new ChildHook(), ParentHook::class . '::missing'];
    $cause = new TypeError('Original failure from the selected hook.');
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics): bool {
        $diagnostics[] = $severity;
        return true;
    });
    try {
        try {
            new CallableInvoker()->call($target, ['failure' => $cause]);
            \PHPUnit\Framework\Assert::fail('The selected magic hook must throw.');
        } catch (TypeError $failure) {
            expect($failure)->toBe($cause)
                ->and($diagnostics)->toBe([E_DEPRECATED]);
        }
    } finally {
        restore_error_handler();
    }
});

test('a qualified magic diagnostic failure keeps its original cause', function (): void {
    $target = [new ChildHook(), ParentHook::class . '::shadowed'];
    $cause = new DomainException('Original failure from the qualification diagnostic.');
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics, $cause): never {
        $diagnostics[] = $severity;
        throw $cause;
    });
    try {
        try {
            new CallableInvoker()->call($target, ['named' => 'unreachable']);
            \PHPUnit\Framework\Assert::fail('The diagnostic exception must be normalized.');
        } catch (InvalidCallableException $failure) {
            expect($failure->getPrevious())->toBe($cause)
                ->and($diagnostics)->toBe([E_DEPRECATED]);
        }
    } finally {
        restore_error_handler();
    }
});

test('a private static magic declaration retains the selected late static scope', function (): void {
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if ($severity === E_WARNING && str_contains($message, 'must have public visibility')) {
            $warnings[] = $message;
            return true;
        }
        return false;
    });
    try {
        $fixture = eval(<<<'PHP'
            namespace Componenta\DI\Tests\Callable\QualifiedNativeMagicInvocationContract;
            class PrivateParentHook
            {
                private static function __callStatic(string $name, array $arguments): array
                {
                    return ['private parent', static::class, $name, $arguments];
                }
            }
            final class PrivateChildHook extends PrivateParentHook
            {
                public static function __callStatic(string $name, array $arguments): array
                {
                    return ['public child', static::class, $name, $arguments];
                }
            }
            return [new PrivateChildHook(), PrivateParentHook::class . '::missing'];
            PHP);
    } finally {
        restore_error_handler();
    }
    if (!is_array($fixture) || !isset($fixture[0], $fixture[1]) || !is_object($fixture[0]) || !is_string($fixture[1])) {
        throw new \LogicException('Expected the private magic receiver and its selector.');
    }
    $receiver = $fixture[0];
    $target = [$receiver, $fixture[1]];

    $native = observe($target, ['named' => 'value'], native: true);
    $actual = observe($target, ['named' => 'value'], native: false);

    expect($warnings)->toHaveCount(1)
        ->and($native)->toBe([['private parent', $receiver::class, 'missing', ['named' => 'value']], [E_DEPRECATED]])
        ->and($actual)->toBe($native);
});
