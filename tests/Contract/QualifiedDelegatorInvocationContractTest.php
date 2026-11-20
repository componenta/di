<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\QualifiedDelegatorInvocation;

use Closure;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;
use TypeError;

class Ancestor
{
    public int $calls = 0;
    public ?Throwable $failure = null;

    public function __construct(public readonly string $label = 'original') {}

    public function decorate(string &$entry, ContainerInterface $container): string
    {
        ++$this->calls;
        $entry .= ':' . $this->label;
        $suffix = $container->get('suffix');
        if (!is_string($suffix)) {
            throw new LogicException('Expected the configured string suffix.');
        }
        return $entry . ':' . $suffix;
    }

    public static function staticDecorate(string $entry): string
    {
        return $entry . ':' . static::class;
    }

    public function strict(int $entry): int
    {
        ++$this->calls;
        return $entry;
    }

    public function fail(string $entry): never
    {
        ++$this->calls;
        throw $this->failure ?? new LogicException($entry);
    }
}

final class Child extends Ancestor
{
    public function decorate(string &$entry, ContainerInterface $container): string
    {
        throw new LogicException('The qualified ancestor route must not dispatch the child override.');
    }
}

final class StaticMagicChild extends Ancestor
{
    public static int $magicCalls = 0;

    /** @param array<array-key, mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): never
    {
        ++self::$magicCalls;
        throw new LogicException('The static magic route is not the selected instance method.');
    }
}

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

function nativeCallable(mixed $callable): callable
{
    if (!is_callable($callable)) {
        throw new LogicException('The qualified ancestor route must be a native callable.');
    }
    return $callable;
}

test('delegators invoke the resolved qualified ancestor while retaining reference arguments and owner replacement', function (string $route): void {
    allowingQualifiedCallable(static function () use ($route): void {
        $owner = new Child();
        $container = (new ContainerBuilder())
            ->addServices(['entry' => 'value', 'suffix' => 'suffix', Child::class => $owner])
            ->build();
        $method = Ancestor::class . '::decorate';
        $callable = nativeCallable([$owner, $method]);
        $native = Closure::fromCallable($callable);
        $entry = 'value';
        expect($native($entry, $container))->toBe('value:original:suffix')
            ->and($entry)->toBe('value:original');
        $owner->calls = 0;

        $specification = match ($route) {
            'object pair' => [$owner, $method],
            'service pair' => [Child::class, $method],
            'instance shorthand' => Child::class . '::' . $method,
            'opaque service' => 'qualified.decorator',
            default => throw new LogicException('Unknown callable route.'),
        };
        if ($route === 'opaque service') {
            $container->set($specification, [$owner, $method]);
        }
        $container->delegator('entry', $specification);

        expect($container->get('entry'))->toBe('value:original:suffix')
            ->and($container->get('entry'))->toBe('value:original:suffix')
            ->and($owner->calls)->toBe(1);

        if ($route !== 'object pair') {
            $replacement = new Child('replacement');
            $route === 'opaque service'
                ? $container->set('qualified.decorator', [$replacement, $method])
                : $container->set(Child::class, $replacement);
            expect($container->get('entry'))->toBe('value:replacement:suffix')
                ->and($container->get('entry'))->toBe('value:replacement:suffix')
                ->and($owner->calls)->toBe(1)
                ->and($replacement->calls)->toBe(1);
        }
    });
})->with(['object pair', 'service pair', 'instance shorthand', 'opaque service']);

test('qualified static delegators preserve the called class', function (bool $object): void {
    allowingQualifiedCallable(static function () use ($object): void {
        $callable = nativeCallable([$object ? new Child() : Child::class, Ancestor::class . '::staticDecorate']);
        $expected = 'value:' . ($object ? Child::class : Ancestor::class);
        $container = (new ContainerBuilder())->addService('entry', 'value')->build();

        expect(call_user_func_array($callable, ['value']))->toBe($expected);
        $container->delegator('entry', $callable);
        expect($container->get('entry'))->toBe($expected)
            ->and($container->get('entry'))->toBe($expected);
    });
})->with(['class receiver' => false, 'object receiver' => true]);

test('qualified delegators preserve strict binding and body failure ownership', function (string $kind): void {
    allowingQualifiedCallable(static function () use ($kind): void {
        $owner = new Child();
        $owner->failure = $kind === 'owned' ? new ResolutionException('body failed') : new LogicException('body failed');
        $callable = [$owner, Ancestor::class . '::' . ($kind === 'strict' ? 'strict' : 'fail')];
        $container = (new ContainerBuilder())->addService('entry', '23')->build();
        $container->delegator('entry', $callable);
        $caught = null;
        try {
            $container->get('entry');
        } catch (Throwable $error) {
            $caught = $error;
        }

        if ($kind === 'owned') {
            expect($caught)->toBe($owner->failure)
                ->and($caught?->getPrevious())->toBeNull()
                ->and($owner->calls)->toBe(1);
        } else {
            expect($caught)->toBeInstanceOf(DelegatorException::class);
            if ($kind === 'strict') {
                expect($caught?->getPrevious())->toBeInstanceOf(TypeError::class)
                    ->and($owner->calls)->toBe(0);
            } else {
                expect($caught?->getPrevious())->toBe($owner->failure)
                    ->and($owner->calls)->toBe(1);
            }
        }
    });
})->with(['strict', 'owned', 'foreign']);

test('qualified instance dependencies retain their owner when its class also exposes static magic', function (bool $pair): void {
    allowingQualifiedCallable(static function () use ($pair): void {
        StaticMagicChild::$magicCalls = 0;
        $owner = new StaticMagicChild();
        $method = Ancestor::class . '::decorate';
        $container = (new ContainerBuilder())
            ->addServices(['entry' => 'value', 'suffix' => 'suffix', StaticMagicChild::class => $owner])
            ->build();
        $container->delegator('entry', $pair
            ? [StaticMagicChild::class, $method]
            : StaticMagicChild::class . '::' . $method);

        expect($container->get('entry'))->toBe('value:original:suffix')
            ->and($container->get('entry'))->toBe('value:original:suffix')
            ->and($owner->calls)->toBe(1);

        $replacement = new StaticMagicChild('replacement');
        $container->set(StaticMagicChild::class, $replacement);

        expect($container->get('entry'))->toBe('value:replacement:suffix')
            ->and($container->get('entry'))->toBe('value:replacement:suffix')
            ->and($owner->calls)->toBe(1)
            ->and($replacement->calls)->toBe(1)
            ->and(StaticMagicChild::$magicCalls)->toBe(0);
    });
})->with(['instance shorthand' => false, 'service pair control' => true]);
