<?php

declare(strict_types=1);

use Componenta\DI\CycleGuard;
use Componenta\DI\Exception\CircularDependencyException;

describe('CycleGuard', function () {
    describe('enter / leave', function () {
        it('accepts ids that are not currently in-flight', function () {
            $guard = new CycleGuard();

            expect(function () use ($guard) {
                $guard->enter('a');
                $guard->enter('b');
            })->not->toThrow(CircularDependencyException::class);
        });

        it('throws when the same id is entered twice without leaving', function () {
            $guard = new CycleGuard();
            $guard->enter('a');

            expect(fn () => $guard->enter('a'))
                ->toThrow(CircularDependencyException::class);
        });

        it('exposes the full resolution chain on the cycle exception', function () {
            $guard = new CycleGuard();
            $guard->enter('a');
            $guard->enter('b');

            try {
                $guard->enter('a');
            } catch (CircularDependencyException $e) {
                expect($e->chain)->toBe(['a', 'b', 'a']);
                return;
            }

            self::fail('expected CircularDependencyException');
        });

        it('allows re-entering an id after it has been left', function () {
            $guard = new CycleGuard();
            $guard->enter('a');
            $guard->leave('a');

            expect(fn () => $guard->enter('a'))
                ->not->toThrow(CircularDependencyException::class);
        });

        it('tolerates leaving an id that was never entered', function () {
            $guard = new CycleGuard();

            expect(fn () => $guard->leave('never-entered'))
                ->not->toThrow(Throwable::class);
        });
    });
});
