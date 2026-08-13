<?php

declare(strict_types=1);

use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Tests\Fixture\SimpleService;

final class InvokableOptionalConstructor
{
    public function __construct(public string $value = 'default') {}
}

final class InvokableRequiredConstructor
{
    public function __construct(public string $value) {}
}

final class InvokableThrowingConstructor
{
    public function __construct()
    {
        throw new RuntimeException('constructor failed');
    }
}

describe('Resolver\\InvokableResolver', function () {
    describe('can()', function () {
        it('returns true only for registered class ids', function () {
            $resolver = new InvokableResolver([SimpleService::class]);

            expect($resolver->can(SimpleService::class))->toBeTrue()
                ->and($resolver->can('not.registered'))->toBeFalse();
        });
    });

    describe('resolve()', function () {
        it('instantiates a registered class with a plain zero-argument new', function () {
            $resolver = new InvokableResolver([SimpleService::class]);

            $instance = $resolver->resolve(SimpleService::class);

            expect($instance)->toBeInstanceOf(SimpleService::class)
                ->and($instance->constructed)->toBeTrue();
        });

        it('produces a fresh instance on each resolve call', function () {
            $resolver = new InvokableResolver([SimpleService::class]);

            expect($resolver->resolve(SimpleService::class))
                ->not->toBe($resolver->resolve(SimpleService::class));
        });

        it('ignores resolution context', function () {
            $resolver = new InvokableResolver([InvokableOptionalConstructor::class]);

            $instance = $resolver->resolve(
                InvokableOptionalConstructor::class,
                ['value' => 'runtime', 0 => 'positional'],
            );

            expect($instance->value)->toBe('default');
        });

        it('throws NotFoundException for an id it does not own', function () {
            expect(fn() => (new InvokableResolver())->resolve('missing'))
                ->toThrow(NotFoundException::class);
        });

        it('wraps constructor argument failures in ResolutionException', function () {
            $resolver = new InvokableResolver([InvokableRequiredConstructor::class]);

            try {
                $resolver->resolve(InvokableRequiredConstructor::class);
            } catch (ResolutionException $e) {
                expect($e->serviceId)->toBe(InvokableRequiredConstructor::class)
                    ->and($e->getPrevious())->toBeInstanceOf(ArgumentCountError::class);

                return;
            }

            self::fail('expected ResolutionException');
        });

        it('wraps constructor throwables in ResolutionException', function () {
            $resolver = new InvokableResolver([InvokableThrowingConstructor::class]);

            try {
                $resolver->resolve(InvokableThrowingConstructor::class);
            } catch (ResolutionException $e) {
                expect($e->serviceId)->toBe(InvokableThrowingConstructor::class)
                    ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class)
                    ->and($e->getPrevious()?->getMessage())->toBe('constructor failed');

                return;
            }

            self::fail('expected ResolutionException');
        });
    });

    describe('definition support', function () {
        it('supportsDefinition is true only for InvokableDefinition', function () {
            $resolver = new InvokableResolver([]);

            expect($resolver->supportsDefinition(new InvokableDefinition(SimpleService::class)))->toBeTrue()
                ->and($resolver->supportsDefinition(new FactoryDefinition(fn() => null)))->toBeFalse();
        });

        it('setDefinition registers the class, making can() and resolve() succeed', function () {
            $resolver = new InvokableResolver([]);

            $resolver->setDefinition('alias-id', new InvokableDefinition(SimpleService::class));

            expect($resolver->can('alias-id'))->toBeTrue()
                ->and($resolver->resolve('alias-id'))->toBeInstanceOf(SimpleService::class);
        });

        it('setDefinition rejects unsupported definition types', function () {
            $resolver = new InvokableResolver([]);

            expect(fn() => $resolver->setDefinition('x', new FactoryDefinition(fn() => null)))
                ->toThrow(InvalidConfigurationException::class);
        });
    });
});
