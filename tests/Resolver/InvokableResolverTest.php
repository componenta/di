<?php

declare(strict_types=1);

use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Tests\Fixture\ServiceWithoutConstructor;
use Componenta\DI\Tests\Fixture\SimpleService;

describe('Resolver\\InvokableResolver', function () {
    describe('can()', function () {
        it('returns true only for registered class ids', function () {
            $resolver = new InvokableResolver([SimpleService::class]);

            expect($resolver->can(SimpleService::class))->toBeTrue()
                ->and($resolver->can('not.registered'))->toBeFalse();
        });
    });

    describe('resolve() without a proxy factory (eager by default)', function () {
        it('instantiates the registered class directly', function () {
            $resolver = new InvokableResolver([SimpleService::class]);

            $instance = $resolver->resolve(SimpleService::class);

            expect($instance)->toBeInstanceOf(SimpleService::class)
                ->and($instance->constructed)->toBeTrue();
        });

        it('produces a fresh instance on each resolve call', function () {
            $resolver = new InvokableResolver([SimpleService::class]);

            $a = $resolver->resolve(SimpleService::class);
            $b = $resolver->resolve(SimpleService::class);

            expect($a)->not->toBe($b);
        });
    });

    describe('resolve() with a real PHP84 proxy factory', function () {
        it('returns an instance of the target class (eager for classes without Lazy/Proxy attributes)', function () {
            $resolver = new InvokableResolver([SimpleService::class], new ProxyFactory());

            $instance = $resolver->resolve(SimpleService::class);

            expect($instance)->toBeInstanceOf(SimpleService::class);
        });

        it('handles classes without a constructor (avoids calling __construct on null)', function () {
            $resolver = new InvokableResolver([ServiceWithoutConstructor::class], new ProxyFactory());

            $instance = $resolver->resolve(ServiceWithoutConstructor::class);

            expect($instance)->toBeInstanceOf(ServiceWithoutConstructor::class)
                ->and($instance->tag)->toBe('empty');
        });
    });

    describe('definition support', function () {
        it('supportsDefinition is true only for InvokableDefinition', function () {
            $resolver = new InvokableResolver([]);

            expect($resolver->supportsDefinition(new InvokableDefinition(SimpleService::class)))->toBeTrue()
                ->and($resolver->supportsDefinition(new FactoryDefinition(fn () => null)))->toBeFalse();
        });

        it('setDefinition registers the class, making can() and resolve() succeed', function () {
            $resolver = new InvokableResolver([]);

            $resolver->setDefinition('alias-id', new InvokableDefinition(SimpleService::class));

            expect($resolver->can('alias-id'))->toBeTrue()
                ->and($resolver->resolve('alias-id'))->toBeInstanceOf(SimpleService::class);
        });

        it('setDefinition rejects unsupported definition types', function () {
            $resolver = new InvokableResolver([]);

            expect(fn () => $resolver->setDefinition('x', new FactoryDefinition(fn () => null)))
                ->toThrow(InvalidConfigurationException::class);
        });
    });
});
