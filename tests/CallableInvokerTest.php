<?php

declare(strict_types=1);

use Componenta\DI\CallableInvoker;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\InvalidCallableException;

describe('CallableInvoker', function () {
    it('implements CallableInvokerInterface', function () {
        expect(new CallableInvoker())->toBeInstanceOf(CallableInvokerInterface::class);
    });

    it('invokes the callable and returns its value', function () {
        $result = (new CallableInvoker())->call(fn (int $a, int $b) => $a + $b, [2, 3]);

        expect($result)->toBe(5);
    });

    it('passes the params list verbatim (no DI, no reordering)', function () {
        $received = null;
        (new CallableInvoker())->call(function (...$args) use (&$received) {
            $received = $args;
        }, ['a', 'b', 'c']);

        expect($received)->toBe(['a', 'b', 'c']);
    });

    it('invokes an already-valid [object, method] callable', function () {
        $service = new class () {
            public function add(int $a, int $b): int { return $a + $b; }
        };

        expect((new CallableInvoker())->call([$service, 'add'], [10, 20]))->toBe(30);
    });

    it('invokes with an empty params list when the callable takes none', function () {
        $result = (new CallableInvoker())->call(fn () => 'no-args');

        expect($result)->toBe('no-args');
    });

    it('lets domain exceptions thrown inside the callable propagate unchanged', function () {
        $boom = new DomainException('boom');

        expect(fn () => (new CallableInvoker())->call(function () use ($boom) {
            throw $boom;
        }))->toThrow($boom);
    });

    it('wraps PHP engine errors into InvalidCallableException with the original Error as previous', function () {
        try {
            (new CallableInvoker())->call(fn (int $x) => $x, [/* too few args */]);
        } catch (InvalidCallableException $e) {
            expect($e->getPrevious())->toBeInstanceOf(ArgumentCountError::class)
                ->and($e->params)->toBe([]);
            return;
        }

        self::fail('expected InvalidCallableException');
    });

    it('wraps TypeError from wrongly-typed arguments into InvalidCallableException', function () {
        expect(fn () => (new CallableInvoker())->call(fn (int $x) => $x, ['not-an-int']))
            ->toThrow(InvalidCallableException::class);
    });
});
