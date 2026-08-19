<?php

declare(strict_types=1);

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

it('allows a custom resolver to classify an immutable parameter target', function (): void {
    $callable = static function (string $value): void {};
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];
    $target = new ParameterTarget($parameter);

    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'value';
        }

        public function resolveParameter(
            ParameterTarget $target,
            ParameterResolutionContext $context,
        ): ?array {
            return [$target->position, 'custom'];
        }
    };

    expect($resolver->supports($target))->toBeTrue()
        ->and($resolver->resolveParameter($target, new ParameterResolutionContext()))
        ->toBe([0, 'custom']);
});
