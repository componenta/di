<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerInterface;
use Reflector;

final class ServiceMethodParameterResolver implements ParameterResolverInterface
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

final class ServiceMethodAttributeHandler implements AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 0;
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return false;
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {}
}

final class ServiceMethodExtensionFactory
{
    public function parameter(ContainerInterface $_container): ParameterResolverInterface
    {
        return new ServiceMethodParameterResolver();
    }

    public function handler(ContainerInterface $_container): AttributeHandlerInterface
    {
        return new ServiceMethodAttributeHandler();
    }
}

function expectServiceMethodExtensions(ContainerInterface $container): void
{
    $parameters = $container->get(ParametersResolver::class);
    $handlers = $container->get(AttributeHandlerRegistry::class);

    expect($parameters)->toBeInstanceOf(ParametersResolver::class)
        ->and(array_any(
            $parameters->resolverList,
            static fn(object $resolver): bool => $resolver instanceof ServiceMethodParameterResolver,
        ))->toBeTrue()
        ->and($handlers)->toBeInstanceOf(AttributeHandlerRegistry::class)
        ->and(array_any(
            $handlers->handlers,
            static fn(object $handler): bool => $handler instanceof ServiceMethodAttributeHandler,
        ))->toBeTrue();
}

it('materializes service method extension factories from dependency configuration', function (): void {
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::SERVICES => [
                'extension.factory' => new ServiceMethodExtensionFactory(),
            ],
            ConfigKey::PARAMETER_RESOLVERS => [
                5000 => ['extension.factory', 'parameter'],
            ],
            ConfigKey::ATTRIBUTE_HANDLERS => [
                ['extension.factory', 'handler'],
            ],
        ],
    )->build();

    expectServiceMethodExtensions($container);
});

it('materializes service method extension factories from the fluent builder API', function (): void {
    $container = (new ContainerBuilder())
        ->addService('extension.factory', new ServiceMethodExtensionFactory())
        ->addParameterResolver(['extension.factory', 'parameter'], 5000)
        ->addAttributeHandler(['extension.factory', 'handler'])
        ->build();

    expectServiceMethodExtensions($container);
});
