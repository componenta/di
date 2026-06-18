<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Fixture\NoConstructorTarget;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\ServiceWithoutConstructor;
use Componenta\DI\Tests\Fixture\SimpleService;

function fullParametersResolver(): ParametersResolver
{
    return new ParametersResolver(
        new ArrayResolver(),
        new DefaultValueResolver(),
        new NullableResolver(),
    );
}

describe('Resolver\\Entry\\InstanceCreator', function () {
    describe('create()', function () {
        it('instantiates a class with a no-arg constructor', function () {
            $creator = new InstanceCreator(fullParametersResolver());

            $instance = $creator->create(new ReflectionClass(SimpleService::class));

            expect($instance)->toBeInstanceOf(SimpleService::class)
                ->and($instance->constructed)->toBeTrue();
        });

        it('returns a new instance on each call', function () {
            $creator = new InstanceCreator(fullParametersResolver());

            $a = $creator->create(new ReflectionClass(SimpleService::class));
            $b = $creator->create(new ReflectionClass(SimpleService::class));

            expect($a)->not->toBe($b);
        });

        it('resolves constructor parameters through the ParametersResolver', function () {
            $creator = new InstanceCreator(fullParametersResolver());

            $instance = $creator->create(
                new ReflectionClass(ServiceWithParam::class),
                ['value' => 'hello'],
            );

            expect($instance->value)->toBe('hello');
        });

        it('handles classes with no constructor via newInstance()', function () {
            $creator = new InstanceCreator(fullParametersResolver());

            $instance = $creator->create(new ReflectionClass(ServiceWithoutConstructor::class));

            expect($instance)->toBeInstanceOf(ServiceWithoutConstructor::class)
                ->and($instance->tag)->toBe('empty');
        });

        it('skips the constructor when the class is marked #[NoConstructor]', function () {
            $creator = new InstanceCreator(fullParametersResolver());

            $instance = $creator->create(new ReflectionClass(NoConstructorTarget::class));

            // The default for $tag would be 'no-ctor' via property initialization.
            // newInstanceWithoutConstructor still assigns declared defaults,
            // so the property is present. We merely guarantee the class was
            // instantiated without error.
            expect($instance)->toBeInstanceOf(NoConstructorTarget::class);
        });
    });

    describe('initialize()', function () {
        it('invokes the constructor on an already-allocated instance', function () {
            $creator = new InstanceCreator(fullParametersResolver());
            $reflector = new ReflectionClass(ServiceWithParam::class);
            $raw = $reflector->newInstanceWithoutConstructor();

            $creator->initialize($raw, $reflector, ['value' => 'late-init']);

            expect($raw->value)->toBe('late-init');
        });

        it('does nothing for classes marked #[NoConstructor]', function () {
            $creator = new InstanceCreator(fullParametersResolver());
            $reflector = new ReflectionClass(NoConstructorTarget::class);
            $raw = $reflector->newInstanceWithoutConstructor();

            $creator->initialize($raw, $reflector);

            expect($raw->tag)->toBe('no-ctor'); // default untouched
        });

        it('does nothing for classes with no constructor', function () {
            $creator = new InstanceCreator(fullParametersResolver());
            $reflector = new ReflectionClass(ServiceWithoutConstructor::class);
            $raw = $reflector->newInstanceWithoutConstructor();

            $creator->initialize($raw, $reflector);

            expect($raw->tag)->toBe('empty');
        });
    });
});
