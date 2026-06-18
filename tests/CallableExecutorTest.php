<?php

declare(strict_types=1);

use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableResolver;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\NullContainer;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

function makeExecutor(
    ?CallableResolverInterface $callableResolver = null,
    ?ParametersResolver $parametersResolver = null,
): CallableExecutor {
    return new CallableExecutor(
        $callableResolver ?? new CallableResolver(new NullContainer()),
        $parametersResolver ?? new ParametersResolver(new ArrayResolver()),
    );
}

describe('CallableExecutor', function () {
    it('implements CallableExecutorInterface', function () {
        expect(makeExecutor())->toBeInstanceOf(CallableExecutorInterface::class);
    });

    describe('call()', function () {
        it('invokes a parameterless callable without asking the parameter resolver for anything', function () {
            $parametersResolver = new class (new ArrayResolver()) extends ParametersResolver {
                public int $calls = 0;

                public function resolve(array $parameters, array $providedParameters = []): array
                {
                    $this->calls++;
                    return parent::resolve($parameters, $providedParameters);
                }
            };

            $result = makeExecutor(parametersResolver: $parametersResolver)->call(fn () => 'done');

            expect($result)->toBe('done')
                ->and($parametersResolver->calls)->toBe(0);
        });

        it('passes provided parameters to the callable by name', function () {
            $executor = makeExecutor();

            $result = $executor->call(fn (int $a, int $b) => $a - $b, ['a' => 10, 'b' => 3]);

            expect($result)->toBe(7);
        });

        it('passes provided parameters to the callable by position', function () {
            $executor = makeExecutor();

            $result = $executor->call(fn (int $a, int $b) => $a - $b, [10, 3]);

            expect($result)->toBe(7);
        });

        it('throws ResolutionException when a parameter cannot be resolved', function () {
            $executor = makeExecutor();

            expect(fn () => $executor->call(fn (int $missing) => $missing))
                ->toThrow(ResolutionException::class);
        });

        it('propagates exceptions thrown inside the callable unchanged', function () {
            $executor = makeExecutor();
            $boom = new DomainException('from callable');

            expect(fn () => $executor->call(function () use ($boom) {
                throw $boom;
            }))->toThrow($boom);
        });

        it('forwards resolver failures as InvalidCallableException', function () {
            $executor = makeExecutor();

            expect(fn () => $executor->call('this-is-not-a-callable'))
                ->toThrow(InvalidCallableException::class);
        });
    });

    describe('resolve()', function () {
        it('delegates to the underlying CallableResolver', function () {
            $recording = new class () implements CallableResolverInterface {
                public array $inputs = [];

                public function resolve(mixed $callable): callable
                {
                    $this->inputs[] = $callable;
                    return fn () => 'resolved';
                }
            };
            $executor = makeExecutor(callableResolver: $recording);

            $result = $executor->resolve('whatever');

            expect($result())->toBe('resolved')
                ->and($recording->inputs)->toBe(['whatever']);
        });
    });
});
