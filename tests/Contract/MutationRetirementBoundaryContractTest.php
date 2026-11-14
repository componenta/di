<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\MutationRetirementBoundary;

use Closure;
use Componenta\DI\Exception\MutationRetirementException;
use Componenta\DI\Internal\MutationRetirement;
use Fiber;
use LogicException;
use WeakReference;

final readonly class ReleaseAction
{
    public function __construct(private Closure $action) {}

    public function __destruct()
    {
        ($this->action)();
    }
}

test('retirement releases every directly retained root in order and collects all delivered failures', function (): void {
    $retirement = new MutationRetirement('entry');
    $events = [];
    $first = new LogicException('first');
    $second = new LogicException('second');
    foreach ([$first, null, $second] as $index => $failure) {
        $retirement->retain(new ReleaseAction(static function () use (&$events, $index, $failure): void {
            $events[] = $index;
            if ($failure !== null) {
                throw $failure;
            }
        }));
    }
    expect($events)->toBe([]);

    try {
        $retirement->release();
        \PHPUnit\Framework\Assert::fail('Expected both retirement failures.');
    } catch (MutationRetirementException $error) {
        expect($error->id)->toBe('entry')
            ->and($error->causes)->toBe([$first, $second])
            ->and($error->getPrevious())->toBe($first)
            ->and($events)->toBe([0, 1, 2]);
    }
    $retirement->release();
    expect($events)->toBe([0, 1, 2])
        ->and(fn() => $retirement->retain('late'))->toThrow(LogicException::class);
});

test('reentrant release is a no-op and cannot add new retired values', function (): void {
    $retirement = new MutationRetirement(null);
    $calls = 0;
    $retirement->retain(new ReleaseAction(static function () use ($retirement, &$calls): void {
        ++$calls;
        $retirement->release();
        expect(fn() => $retirement->retain('late'))->toThrow(LogicException::class);
    }));

    $retirement->release();
    $retirement->release();

    expect($calls)->toBe(1);
});

test('a second release does not join or replay a suspended first release', function (): void {
    $retirement = new MutationRetirement('suspended');
    $events = [];
    $retirement->retain(new ReleaseAction(static function () use (&$events): void {
        $events[] = 'started';
        Fiber::suspend();
        $events[] = 'finished';
    }));
    $retirement->retain(new ReleaseAction(static function () use (&$events): void {
        $events[] = 'last';
    }));
    $fiber = new Fiber(static fn() => $retirement->release());
    $fiber->start();
    try {
        $retirement->release();
        expect($events)->toBe(['started'])
            ->and(fn() => $retirement->retain('late'))->toThrow(LogicException::class);
        $fiber->resume();
        expect($events)->toBe(['started', 'finished', 'last']);
    } finally {
        if ($fiber->isSuspended()) {
            $fiber->resume();
        }
    }
});

final class CyclicValue
{
    public self $cycle;
}

test('retirement drops ownership without forcing collection of application cycles', function (): void {
    $wasEnabled = gc_enabled();
    gc_disable();
    try {
        $value = new CyclicValue();
        $value->cycle = $value;
        $reference = WeakReference::create($value);
        $retirement = new MutationRetirement('cycle');
        $retirement->retain($value);
        unset($value);

        $retirement->release();

        expect($reference->get())->toBeInstanceOf(CyclicValue::class);
        gc_collect_cycles();
        expect($reference->get())->toBeNull();
    } finally {
        if ($wasEnabled) {
            gc_enable();
        }
    }
});
