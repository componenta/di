<?php

declare(strict_types=1);

use Componenta\DI\AliasResolver;
use Componenta\DI\AliasResolverInterface;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;

describe('AliasResolver', function () {
    it('implements AliasResolverInterface', function () {
        expect(new AliasResolver())->toBeInstanceOf(AliasResolverInterface::class);
    });

    describe('resolve()', function () {
        it('returns the id unchanged when it is not a registered alias', function () {
            expect((new AliasResolver())->resolve('service'))->toBe('service');
        });

        it('walks the alias chain to the terminal target', function () {
            $resolver = new AliasResolver([
                'a' => 'b',
                'b' => 'c',
                'c' => 'RealService',
            ]);

            expect($resolver->resolve('a'))->toBe('RealService')
                ->and($resolver->resolve('b'))->toBe('RealService')
                ->and($resolver->resolve('c'))->toBe('RealService');
        });

        it('reflects a mid-chain update after calling set()', function () {
            $resolver = new AliasResolver([
                'a' => 'b',
                'b' => 'Old',
            ]);
            expect($resolver->resolve('a'))->toBe('Old');

            $resolver->set('b', 'New');

            expect($resolver->resolve('a'))->toBe('New');
        });

        it('defensively throws on cycle even when validation was skipped at construction', function () {
            $resolver = new AliasResolver(
                ['a' => 'b', 'b' => 'a'],
                skipValidation: true,
            );

            expect(fn () => $resolver->resolve('a'))
                ->toThrow(CircularDependencyException::class);
        });
    });

    describe('set()', function () {
        it('registers the alias so it resolves to the target', function () {
            $resolver = new AliasResolver();

            $resolver->set('logger', 'FileLogger');

            expect($resolver->resolve('logger'))->toBe('FileLogger');
        });

        it('returns the resolver instance for fluent chaining', function () {
            $resolver = new AliasResolver();

            expect($resolver->set('a', 'b'))->toBe($resolver);
        });

        it('throws InvalidConfigurationException for a self-referencing alias', function () {
            expect(fn () => (new AliasResolver())->set('a', 'a'))
                ->toThrow(InvalidConfigurationException::class, 'Self-referencing alias: "a"');
        });

        it('throws CircularDependencyException when the new mapping would close a cycle', function () {
            $resolver = new AliasResolver(['a' => 'b', 'b' => 'c']);

            try {
                $resolver->set('c', 'a');
            } catch (CircularDependencyException $e) {
                expect($e->chain)->toBe(['c', 'a', 'b', 'c']);
                return;
            }

            self::fail('expected CircularDependencyException');
        });

        it('leaves the map untouched when the update is rejected for a cycle', function () {
            $resolver = new AliasResolver(['a' => 'b', 'b' => 'c']);

            try {
                $resolver->set('c', 'a');
            } catch (CircularDependencyException) {
                // expected
            }

            expect(iterator_to_array($resolver))->toBe(['a' => 'b', 'b' => 'c']);
        });
    });

    describe('has()', function () {
        it('returns true only for registered alias keys, not targets', function () {
            $resolver = new AliasResolver(['alias' => 'target']);

            expect($resolver->has('alias'))->toBeTrue()
                ->and($resolver->has('target'))->toBeFalse()
                ->and($resolver->has('unknown'))->toBeFalse();
        });
    });

    describe('unset()', function () {
        it('removes the alias from the registry', function () {
            $resolver = new AliasResolver(['a' => 'b']);

            $resolver->unset('a');

            expect($resolver->has('a'))->toBeFalse();
        });

        it('stops chain resolution at the removed link', function () {
            $resolver = new AliasResolver(['a' => 'b', 'b' => 'Target']);
            expect($resolver->resolve('a'))->toBe('Target');

            $resolver->unset('b');

            // 'a' still maps to 'b', but 'b' is no longer an alias => resolves to 'b'.
            expect($resolver->resolve('a'))->toBe('b');
        });

        it('returns the resolver instance for fluent chaining', function () {
            $resolver = new AliasResolver(['a' => 'b']);

            expect($resolver->unset('a'))->toBe($resolver);
        });

        it('is a no-op for an id that is not a registered alias', function () {
            $resolver = new AliasResolver(['a' => 'b']);

            $resolver->unset('not-an-alias');

            expect(iterator_to_array($resolver))->toBe(['a' => 'b']);
        });
    });

    describe('iteration', function () {
        it('yields the alias->target pairs', function () {
            $pairs = ['a' => 'TargetA', 'b' => 'TargetB'];

            expect(iterator_to_array(new AliasResolver($pairs)))->toBe($pairs);
        });

        it('reflects later set() calls', function () {
            $resolver = new AliasResolver(['a' => 'b']);
            $resolver->set('c', 'd');

            expect(iterator_to_array($resolver))->toBe(['a' => 'b', 'c' => 'd']);
        });
    });

    describe('constructor validation', function () {
        it('throws InvalidConfigurationException for self-referencing alias in the map', function () {
            expect(fn () => new AliasResolver(['x' => 'x']))
                ->toThrow(InvalidConfigurationException::class);
        });

        it('throws CircularDependencyException for a cycle across the map', function () {
            try {
                new AliasResolver(['a' => 'b', 'b' => 'c', 'c' => 'a']);
            } catch (CircularDependencyException $e) {
                expect($e->chain)->toBe(['c', 'a', 'b', 'c']);
                return;
            }

            self::fail('expected CircularDependencyException');
        });

        it('accepts a cyclic map when skipValidation is true (deferred detection)', function () {
            $resolver = new AliasResolver(['a' => 'b', 'b' => 'a'], skipValidation: true);

            expect($resolver->has('a'))->toBeTrue()
                ->and($resolver->has('b'))->toBeTrue();
        });
    });

    describe('caching', function () {
        it('invalidates the resolution cache when a link is updated', function () {
            $resolver = new AliasResolver(['a' => 'b', 'b' => 'Old']);
            expect($resolver->resolve('a'))->toBe('Old');

            $resolver->set('b', 'New');

            expect($resolver->resolve('a'))->toBe('New');
        });

        it('invalidates the resolution cache on unset()', function () {
            $resolver = new AliasResolver(['a' => 'b', 'b' => 'Target']);
            expect($resolver->resolve('a'))->toBe('Target');

            $resolver->unset('b');

            expect($resolver->resolve('a'))->toBe('b');
        });
    });
});
