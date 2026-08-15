<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerInterface;

interface ExtensionPrecedenceMissingDependency {}

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

final class ExtensionPrecedenceStaticFactory
{
    public function __construct(ExtensionPrecedenceMissingDependency $_missing) {}

    public static function create(ContainerInterface $_container): ParameterResolverInterface
    {
        return new ExtensionPrecedenceResolver();
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

it('preserves a native static array extension factory instead of autowiring its owner', function () {
    $container = (new ContainerBuilder())
        ->addParameterResolver([ExtensionPrecedenceStaticFactory::class, 'create'], 5001)
        ->build();
    $parameters = $container->get(ParametersResolver::class);

    expect($parameters)->toBeInstanceOf(ParametersResolver::class)
        ->and(array_find(
            $parameters->resolverList,
            static fn(object $resolver): bool => $resolver instanceof ExtensionPrecedenceResolver,
        ))->toBeInstanceOf(ExtensionPrecedenceResolver::class);
});
