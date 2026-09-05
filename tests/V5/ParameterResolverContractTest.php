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
