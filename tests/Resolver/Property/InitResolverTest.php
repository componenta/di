<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';
require_once __DIR__ . '/../../Fixture/functions.php';

use Componenta\DI\CallableInvoker;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Property\InitResolver;
use Componenta\DI\Tests\Fixture\TypedProperties;
use Psr\Container\ContainerExceptionInterface;

use function Componenta\DI\Tests\Fixture\typedProperty;

function fakeInvoker(callable $impl): CallableInvokerInterface
{
    return new class ($impl) implements CallableInvokerInterface {
        /** @var callable */
        private $impl;

        public function __construct(callable $impl)
        {
            $this->impl = $impl;
        }

        public function call(mixed $callable, array $params = []): mixed
        {
            return ($this->impl)($callable, $params);
        }
    };
}

describe('Property\\InitResolver', function () {
    it('returns null for a property without the #[Init] attribute', function () {
        $resolver = new InitResolver(new CallableInvoker());

        expect($resolver->resolveProperty(typedProperty(TypedProperties::class, 'plain')))
            ->toBeNull();
    });

    it('invokes the callable from the attribute with the supplied params and returns its value', function () {
        $capturedCallable = null;
        $capturedParams = null;
        $resolver = new InitResolver(fakeInvoker(function ($callable, $params) use (&$capturedCallable, &$capturedParams) {
            $capturedCallable = $callable;
            $capturedParams = $params;
            return 'produced';
        }));
        $property = typedProperty(TypedProperties::class, 'computed');

        $result = $resolver->resolveProperty($property);

        expect($result)->toBe([$property, 'produced'])
            ->and($capturedCallable)->toBe('Componenta\\DI\\Tests\\Fixture\\globalCallableFixture')
            ->and($capturedParams)->toBe([21]);
    });

    it('actually executes the callable end-to-end with a real invoker', function () {
        $resolver = new InitResolver(new CallableInvoker());
        $property = typedProperty(TypedProperties::class, 'computed');

        $result = $resolver->resolveProperty($property);

        // globalCallableFixture(21) => 42
        expect($result)->toBe([$property, 42]);
    });

    it('wraps foreign Throwables from the invoker into ResolutionException', function () {
        $boom = new RuntimeException('invoker exploded');
        $resolver = new InitResolver(fakeInvoker(function () use ($boom) {
            throw $boom;
        }));
        $property = typedProperty(TypedProperties::class, 'computed');

        try {
            $resolver->resolveProperty($property);
        } catch (ResolutionException $e) {
            expect($e->getPrevious())->toBe($boom);
            return;
        }

        self::fail('expected ResolutionException');
    });

    it('lets ContainerExceptionInterface exceptions propagate unchanged', function () {
        $original = NotFoundException::forService('something');
        $resolver = new InitResolver(fakeInvoker(function () use ($original) {
            throw $original;
        }));
        $property = typedProperty(TypedProperties::class, 'computed');

        try {
            $resolver->resolveProperty($property);
        } catch (Throwable $caught) {
            expect($caught)->toBeInstanceOf(ContainerExceptionInterface::class)
                ->and($caught)->toBe($original)
                ->and($caught)->not->toBeInstanceOf(ResolutionException::class);
            return;
        }

        self::fail('expected container exception to propagate');
    });
});
