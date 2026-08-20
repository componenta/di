<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

it('keeps parameter resolution as an explicit extension contract', function (): void {
    $interface = new ReflectionClass(ParameterResolverInterface::class);

    expect($interface->hasMethod('supports'))->toBeTrue()
        ->and($interface->hasMethod('resolveParameter'))->toBeTrue()
        ->and((new ReflectionMethod(ParameterResolverInterface::class, 'resolveParameter'))
            ->getParameters()[1]
            ->getType()?->getName())
        ->toBe(ParameterResolutionContext::class);
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
