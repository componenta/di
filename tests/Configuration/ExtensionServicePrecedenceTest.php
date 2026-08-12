<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;

final class ExtensionPrecedenceResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

it('prefers an extension service id over a same-named native callable', function () {
    $resolver = new ExtensionPrecedenceResolver();
    $container = (new ContainerBuilder())
        ->addService('strlen', $resolver)
        ->addParameterResolver('strlen', 5000)
        ->build();

    $parameters = $container->get(ParametersResolver::class);

    expect($parameters)->toBeInstanceOf(ParametersResolver::class)
        ->and($parameters->resolverList)->toContain($resolver);
});
