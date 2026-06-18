<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Entry\PropertyInjector;
use Componenta\DI\Resolver\Property\ArrayResolver;
use Componenta\DI\Resolver\Property\PropertiesResolver;
use Componenta\DI\Tests\Fixture\InjectableTargets;
use Componenta\DI\Tests\Fixture\LooseInjectable;

describe('Resolver\\Entry\\PropertyInjector', function () {
    it('writes resolved values to writable properties', function () {
        $injector = new PropertyInjector(new PropertiesResolver(new ArrayResolver()));
        $instance = new LooseInjectable();

        $injector->inject(new ReflectionClass($instance), $instance, ['a' => 'A-injected', 'b' => 'B-injected']);

        expect($instance->a)->toBe('A-injected')
            ->and($instance->b)->toBe('B-injected');
    });

    it('leaves properties unchanged when context has no matching key', function () {
        $injector = new PropertyInjector(new PropertiesResolver(new ArrayResolver()));
        $instance = new LooseInjectable();

        $injector->inject(new ReflectionClass($instance), $instance, ['a' => 'A-only']);

        expect($instance->a)->toBe('A-only')
            ->and($instance->b)->toBe('b-default');
    });

    it('skips static properties (never writes class-level state)', function () {
        InjectableTargets::$staticProp = 'before';
        $injector = new PropertyInjector(new PropertiesResolver(new ArrayResolver()));
        $instance = new InjectableTargets();

        $injector->inject(new ReflectionClass($instance), $instance, ['staticProp' => 'overwritten']);

        expect(InjectableTargets::$staticProp)->toBe('before');
    });

    it('skips promoted properties (owned by the constructor)', function () {
        $injector = new PropertyInjector(new PropertiesResolver(new ArrayResolver()));
        $instance = new InjectableTargets(promoted: 'original');

        $injector->inject(new ReflectionClass($instance), $instance, ['promoted' => 'overwritten']);

        expect($instance->promoted)->toBe('original');
    });

    it('skips already-initialized readonly properties', function () {
        $injector = new PropertyInjector(new PropertiesResolver(new ArrayResolver()));
        $instance = new InjectableTargets();

        $injector->inject(new ReflectionClass($instance), $instance, ['readonlyInitialized' => 'overwritten']);

        expect($instance->readonlyInitialized)->toBe('ctor-set');
    });

    it('does nothing when no context value matches any property', function () {
        $injector = new PropertyInjector(new PropertiesResolver(new ArrayResolver()));
        $instance = new LooseInjectable();

        $injector->inject(new ReflectionClass($instance), $instance);

        expect($instance->a)->toBe('a-default')
            ->and($instance->b)->toBe('b-default');
    });
});
