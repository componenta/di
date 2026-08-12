<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class BootstrapExtensionAotStamp {}

final class BootstrapExtensionAotResolver implements ParameterResolverInterface
{
    #[BootstrapExtensionAotStamp]
    public string $state = 'unhandled';

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

final class BootstrapExtensionAotHandler implements AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 0;
    }

    public function supportsAttribute(string $attributeClass, \Reflector $target): bool
    {
        return $target instanceof \ReflectionProperty
            && $attributeClass === BootstrapExtensionAotStamp::class;
    }

    public function handle(
        object $attribute,
        \Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof BootstrapExtensionAotStamp
            || !$target instanceof \ReflectionProperty
        ) {
            throw new LogicException('Unexpected bootstrap parity target.');
        }

        if ($context->claimProperty($target)) {
            $context->writeProperty($target, 'handled');
        }
    }
}

final readonly class BootstrapExtensionAotRoot {}

function bootstrapExtensionAotBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addParameterResolver(BootstrapExtensionAotResolver::class, 5000)
        ->addAttributeHandler(BootstrapExtensionAotHandler::class);
}

function bootstrapExtensionAotResolverState(ParametersResolver $parameters): string
{
    foreach ($parameters->resolverList as $resolver) {
        if ($resolver instanceof BootstrapExtensionAotResolver) {
            return $resolver->state;
        }
    }

    throw new LogicException('Custom bootstrap resolver is missing.');
}

it('keeps bootstrap extensions on the same lifecycle path in development and production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-bootstrap-extension-aot-' . bin2hex(random_bytes(5));

    try {
        $development = bootstrapExtensionAotBuilder()->build();
        $compiler = bootstrapExtensionAotBuilder();
        $factories = $compiler->compileFactories(
            [
                BootstrapExtensionAotRoot::class,
                BootstrapExtensionAotResolver::class,
            ],
            $directory,
        );

        expect($factories)->not->toHaveKey(BootstrapExtensionAotResolver::class);

        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                    ConfigKey::PARAMETER_RESOLVERS => [
                        5000 => BootstrapExtensionAotResolver::class,
                    ],
                    ConfigKey::ATTRIBUTE_HANDLERS => [
                        BootstrapExtensionAotHandler::class,
                    ],
                ],
            ],
            $directory,
        )->build();

        $developmentParameters = $development->get(ParametersResolver::class);
        $productionParameters = $production->get(ParametersResolver::class);

        expect($developmentParameters)->toBeInstanceOf(ParametersResolver::class)
            ->and($productionParameters)->toBeInstanceOf(ParametersResolver::class)
            ->and(bootstrapExtensionAotResolverState($productionParameters))
            ->toBe(bootstrapExtensionAotResolverState($developmentParameters))
            ->toBe('unhandled');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});
