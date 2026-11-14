<?php

declare(strict_types=1);

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;

it('keeps parameter resolution as an explicit extension contract', function (): void {
    $interface = new ReflectionClass(ParameterResolverInterface::class);
    $type = (new ReflectionMethod(ParameterResolverInterface::class, 'resolveParameter'))
        ->getParameters()[1]
        ->getType();

    if (!$type instanceof ReflectionNamedType) {
        throw new LogicException('Expected resolveParameter context to have a named type.');
    }

    expect($interface->hasMethod('supports'))->toBeTrue()
        ->and($interface->hasMethod('resolveParameter'))->toBeTrue();
    expect($type->getName())->toBe(ParameterResolutionContext::class);
});

it('custom parameter resolvers participate in public callable resolution', function (): void {
    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'value';
        }

        public function resolveParameter(
            ParameterTarget $target,
            ParameterResolutionContext $context,
        ): ?array {
            return $target->name === 'value'
                ? [$target->position, 'custom']
                : null;
        }
    };
    $container = (new ContainerBuilder())
        ->addParameterResolver($resolver, 2000)
        ->build();

    expect($container->call(static fn(string $value): string => $value))->toBe('custom');
});

it('rejects custom resolver results for the wrong parameter position', function (): void {
    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'value';
        }

        public function resolveParameter(
            ParameterTarget $target,
            ParameterResolutionContext $context,
        ): array {
            return [$target->position + 1, 'custom'];
        }
    };
    $container = (new ContainerBuilder())
        ->addParameterResolver($resolver, 2000)
        ->build();

    expect(fn() => $container->call(static fn(string $value): string => $value))
        ->toThrow(ResolutionException::class, 'expected [position 0, value]');
});

it('rejects custom resolver values that violate the declared parameter type', function (): void {
    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'value';
        }

        public function resolveParameter(
            ParameterTarget $target,
            ParameterResolutionContext $context,
        ): array {
            return [$target->position, new \stdClass()];
        }
    };
    $container = (new ContainerBuilder())
        ->addParameterResolver($resolver, 2000)
        ->build();

    expect(fn() => $container->call(static fn(string $value): string => $value))
        ->toThrow(ResolutionException::class, 'does not satisfy the declared type');
});

it(
    'rejects malformed resolver tuples before invoking the callable and permits recovery',
    /** @param array<mixed> $malformed */
    function (array $malformed): void {
        $resolver = new class () implements ParameterResolverInterface {
            /** @var array<mixed> */
            public array $result = [0, 'initial'];

            public function supports(ParameterTarget $target): bool
            {
                return $target->name === 'value';
            }

            public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
            {
                // @phpstan-ignore return.type (A broken extension deliberately returns malformed tuples.)
                return $this->result;
            }
        };
        $receiver = new class () {
            public int $calls = 0;

            public function accept(string $value): string
            {
                ++$this->calls;
                return $value;
            }
        };
        $container = (new ContainerBuilder())->addParameterResolver($resolver, 2000)->build();
        expect($container->call([$receiver, 'accept']))->toBe('initial');

        $resolver->result = $malformed;

        expect(fn() => $container->call([$receiver, 'accept']))
            ->toThrow(ResolutionException::class, 'expected [position 0, value]');
        expect($receiver->calls)->toBe(1);

        $resolver->result = [0, 'recovered'];
        expect($container->call([$receiver, 'accept']))->toBe('recovered')
            ->and($receiver->calls)->toBe(2);
    },
)->with([
    'empty result' => [[]],
    'missing position' => [[1 => 'unexpected']],
    'missing value' => [[0]],
    'non-integer position' => [['0', 'unexpected']],
    'extra numeric entry' => [[0, 'unexpected', 'ignored']],
    'extra named entry' => [[0, 'unexpected', 'metadata' => 'ignored']],
]);
