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

    describe('track()', function () {
        it('returns the value produced by the action', function () {
            $guard = new CycleGuard();

            $result = $guard->track('a', fn () => 'value');

            expect($result)->toBe('value');
        });

        it('releases the id so a subsequent track of the same id works', function () {
            $guard = new CycleGuard();
            $guard->track('a', fn () => null);

            $result = $guard->track('a', fn () => 'again');

            expect($result)->toBe('again');
        });

        it('detects a cycle when the action re-enters the same id', function () {
            $guard = new CycleGuard();

            expect(fn () => $guard->track('a', fn () => $guard->enter('a')))
                ->toThrow(CircularDependencyException::class);
        });

        it('releases the id even when the action throws', function () {
            $guard = new CycleGuard();

            try {
                $guard->track('a', function () {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
                // expected - the observable guarantee is that the id was
                // released despite the action throwing; verified below.
            }

            // If track() failed to clean up, this second enter would throw
            // with CircularDependencyException.
            expect(fn () => $guard->enter('a'))
                ->not->toThrow(CircularDependencyException::class);
        });
    });
});
