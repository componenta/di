<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StandaloneParameterFallback;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionFunction;
use ReflectionParameter;

test('standalone parameter pipelines preserve native optional omission without a bootstrap guard', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $pipeline->add(new ArrayResolver());
    $pipeline->seal();
    $values = ['first', 'second', 'third'];
    $arguments = $pipeline->resolve(
        new ReflectionFunction('array_slice')->getParameters(),
        ['array' => $values, 'offset' => 1, 'preserve_keys' => true],
    );

    expect($arguments)->toBe([$values, 1, 'preserve_keys' => true])
        ->and(new ReflectionFunction('array_slice')->invokeArgs($arguments))->toBe(array_slice($values, 1, preserve_keys: true))
        ->and($pipeline->resolve(new ReflectionFunction('rand')->getParameters()))->toBe([]);

    $required = new ReflectionFunction(static fn(string $value): string => $value);
    expect(fn() => $pipeline->resolve($required->getParameters()))->toThrow(ResolutionException::class);
});

test('the public attribute resolver declines an unhandled parameter instead of consuming supplied input', function (): void {
    $resolver = new AttributeParameterResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $target = new ParameterTarget(new ReflectionParameter(static fn(string $value): string => $value, 'value'));

    expect($resolver->supports($target))->toBeFalse()
        ->and($resolver->resolveParameter($target, new ParameterResolutionContext()))->toBeNull()
        ->and($resolver->resolveParameter($target, new ParameterResolutionContext(['value' => 'caller'])))->toBeNull();
});
