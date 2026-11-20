<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\QualifiedCallableParameter;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;
use Fiber;
use LogicException;
use WeakReference;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Dependency {}

class Ancestor
{
    public function __construct(public readonly string $label = 'original') {}

    /** @return array{Dependency,string,class-string,string} */
    public function consume(Dependency $dependency, #[EntryId('qualified.label')] string $value): array
    {
        return [$dependency, $value, static::class, $this->label];
    }

    /** @return array{Dependency,string,class-string,string} */
    public static function consumeStatic(Dependency $dependency, #[EntryId('qualified.label')] string $value): array
    {
        return [$dependency, $value, static::class, 'static'];
    }

    /** @return array<array-key,string> */
    public function variadic(string $first, string ...$values): array
    {
        return [$first, ...$values];
    }

    public function reference(string &$value): string
    {
        return $value = 'changed';
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public function __call(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }
}

final class Child extends Ancestor
{
    public function consume(Dependency $dependency, string $value): array
    {
        throw new LogicException('The qualified route must preserve the ancestor declaration.');
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

function builder(bool $uncached): ContainerBuilder
{
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

function nativeCallable(mixed $callable): callable
{
    if (!is_callable($callable)) {
        throw new LogicException('The selected ancestor route must be natively callable.');
    }
    return $callable;
}

test('qualified callable routes resolve the selected ancestor parameters on cold and repeated calls', function (string $route, bool $uncached): void {
    allowingQualifiedCallable(static function () use ($route, $uncached): void {
        $owner = new Child();
        $static = str_starts_with($route, 'static');
        $method = Ancestor::class . ($static ? '::consumeStatic' : '::consume');
        $nativePair = [$route === 'static class' ? Child::class : $owner, $method];
        $native = Closure::fromCallable(nativeCallable($nativePair));
        $dependency = new Dependency();
        $calledClass = $route === 'static class' ? Ancestor::class : Child::class;
        $ownerLabel = $static ? 'static' : 'original';
        expect($native($dependency, 'native'))->toBe([$dependency, 'native', $calledClass, $ownerLabel]);
        $container = builder($uncached)->addServices([
            Dependency::class => $dependency,
            'qualified.label' => 'configured',
            'qualified.owner' => $owner,
            Child::class => $owner,
            'qualified.callable' => $nativePair,
        ])->build();
        $callable = match ($route) {
            'service pair' => ['qualified.owner', $method],
            'instance shorthand' => Child::class . '::' . $method,
            'exact callable service' => 'qualified.callable',
            default => $nativePair,
        };

        expect($container->call($callable))->toBe([$dependency, 'configured', $calledClass, $ownerLabel]);
        $replacement = new Dependency();
        $container->set(Dependency::class, $replacement);
        $container->set('qualified.label', 'updated');
        expect($container->call($callable))->toBe([$replacement, 'updated', $calledClass, $ownerLabel])
            ->and($container->call($callable, ['value' => 'explicit', 'unused' => 'ignored']))
            ->toBe([$replacement, 'explicit', $calledClass, $ownerLabel]);

        if (in_array($route, ['service pair', 'instance shorthand', 'exact callable service'], true)) {
            $nextOwner = new Child('replacement');
            $container->set('qualified.owner', $nextOwner);
            $container->set(Child::class, $nextOwner);
            $container->set('qualified.callable', [$nextOwner, $method]);
            expect($container->call($callable))->toBe([$replacement, 'updated', Child::class, 'replacement']);
        }
    });
})->with(['object pair', 'service pair', 'instance shorthand', 'exact callable service', 'static object', 'static class'])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

test('qualified parameter plans retain variadic binding and reject unsupported references', function (bool $uncached): void {
    allowingQualifiedCallable(static function () use ($uncached): void {
        $container = builder($uncached)->build();
        $owner = new Child();
        $variadic = [$owner, Ancestor::class . '::variadic'];
        expect($container->call($variadic, ['first' => 'head', 'values' => ['tail', 'named' => 'last']]))
            ->toBe(['head', 'tail', 'named' => 'last']);
        $value = 'unchanged';
        expect(fn() => $container->call([$owner, Ancestor::class . '::reference'], ['value' => &$value]))
            ->toThrow(ResolutionException::class, 'By-reference parameters are not supported')
            ->and($value)->toBe('unchanged');
        $arguments = ['positional', 'named' => 'value'];
        expect($container->call([$owner, 'missing'], $arguments))->toBe(['missing', $arguments]);
    });
})->with(['cached plans' => false, 'non-memoized plans' => true]);

class MagicAncestor
{
    private function privateInstance(string $hidden = 'instance default'): string
    {
        return $hidden;
    }

    private static function privateStatic(string $hidden = 'static default'): string
    {
        return $hidden;
    }

    public function accessible(string $method): Closure
    {
        return $method === 'privateStatic' ? self::privateStatic(...) : $this->privateInstance(...);
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,string,array<array-key,mixed>}
     */
    public function __call(string $method, array $arguments): array
    {
        return ['instance', $method, $arguments];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return ['static', $method, $arguments];
    }
}

final class MagicChild extends MagicAncestor {}

test('qualified magic routes preserve native arguments without using an inaccessible method signature', function (bool $uncached, bool $object, string $method): void {
    allowingQualifiedCallable(static function () use ($uncached, $object, $method): void {
        $container = builder($uncached)->build();
        $arguments = ['positional', 'named' => 'value'];
        if ($method !== 'missing') {
            expect(new MagicChild()->accessible($method)())->toBe($method === 'privateStatic' ? 'static default' : 'instance default');
        }
        foreach ([MagicAncestor::class, MagicChild::class] as $qualifier) {
            $callable = nativeCallable([$object ? new MagicChild() : MagicChild::class, $qualifier . '::' . $method]);
            $expected = [$object && $qualifier === MagicChild::class ? 'instance' : 'static', $method, $arguments];
            expect(call_user_func_array($callable, $arguments))->toBe($expected)
                ->and($container->call($callable, $arguments))->toBe($expected)
                ->and($container->call($callable, $arguments))->toBe($expected);
        }
    });
})->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['class receiver' => false, 'object receiver' => true])
    ->with(['missing', 'privateInstance', 'privateStatic']);

final class Lifetime
{
    /** @var WeakReference<TemporaryReceiver>|null */
    public static ?WeakReference $owner = null;
    public static bool $fail = false;
    public static bool $suspend = false;
    public static ?LogicException $failure = null;
}

class TemporaryAncestor
{
    public function consumeInstance(Dependency $dependency): bool
    {
        return self::consume($dependency);
    }

    public static function consume(Dependency $dependency): bool
    {
        if (Lifetime::$suspend) {
            Fiber::suspend(Lifetime::$owner?->get() !== null);
        }
        if (Lifetime::$fail) {
            throw Lifetime::$failure ?? new LogicException('Missing expected body failure.');
        }
        return Lifetime::$owner?->get() !== null;
    }
}

final class TemporaryReceiver extends TemporaryAncestor {}

/** @return array{TemporaryReceiver,string} */
function temporaryCallable(bool $instance): array
{
    $receiver = new TemporaryReceiver();
    Lifetime::$owner = WeakReference::create($receiver);
    return [$receiver, TemporaryAncestor::class . ($instance ? '::consumeInstance' : '::consume')];
}

test('qualified parameter metadata retains temporary receivers only through the active invocation', function (bool $uncached, string $mode, bool $instance): void {
    allowingQualifiedCallable(static function () use ($uncached, $mode, $instance): void {
        $container = builder($uncached)->addService(Dependency::class, new Dependency())->build();
        Lifetime::$fail = $mode === 'body failure';
        Lifetime::$suspend = $mode === 'fiber';
        Lifetime::$failure = new LogicException('Exact qualified body failure.');
        $invoke = static fn(): mixed => $container->call(temporaryCallable($instance));
        foreach ([1, 2] as $_attempt) {
            if ($mode === 'fiber') {
                $fiber = new Fiber($invoke);
                expect($fiber->start())->toBeTrue()->and(Lifetime::$owner?->get())->not->toBeNull();
                $fiber->resume();
                expect($fiber->getReturn())->toBeTrue();
                unset($fiber);
            } elseif (Lifetime::$fail) {
                try {
                    $invoke();
                    \PHPUnit\Framework\Assert::fail('The target body must fail.');
                } catch (LogicException $error) {
                    expect($error)->toBe(Lifetime::$failure);
                }
                // The exception trace deliberately retains invocation arguments.
                Lifetime::$failure = new LogicException('Exact qualified body failure.');
                unset($error);
            } else {
                expect($invoke())->toBeTrue();
            }
            gc_collect_cycles();
            expect(Lifetime::$owner?->get())->toBeNull();
        }
        Lifetime::$failure = null;
    });
})->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['success', 'body failure', 'fiber'])
    ->with(['static receiver' => false, 'instance receiver' => true]);
