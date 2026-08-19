<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class DeferredValue {}

final readonly class DeferredValueResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(DeferredValue::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target)
            ? [$target->position, 'from-service-method']
            : null;
    }
}

final readonly class DeferredConventionResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'fallback';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target)
            ? [$target->position, 'from-deferred-resolver']
            : null;
    }
}

final readonly class DeferredExtensionFactoryService
{
    public function attribute(ContainerInterface $container): AttributeDefinition
    {
        return new AttributeDefinition(
            DeferredValue::class,
            handler: null,
            capabilities: [ValueProvider::class],
        );
    }

    public function valueResolver(ContainerInterface $container): ParameterResolverInterface
    {
        return new DeferredValueResolver();
    }

    public function conventionResolver(ContainerInterface $container): ParameterResolverInterface
    {
        return new DeferredConventionResolver();
    }
}

final readonly class DeferredExtensionDto
{
    public function __construct(
        #[DeferredValue]
        public string $value,
        public string $fallback,
    ) {}
}

test('builder materializes deferred attribute and parameter resolver service methods', function (): void {
    $container = (new ContainerBuilder())
        ->addService(DeferredExtensionFactoryService::class, new DeferredExtensionFactoryService())
        ->addAttributeDefinition([DeferredExtensionFactoryService::class, 'attribute'])
        ->addParameterResolver([DeferredExtensionFactoryService::class, 'valueResolver'], 750)
        ->addParameterResolver([DeferredExtensionFactoryService::class, 'conventionResolver'], 350)
        ->build();

    $dto = $container->make(DeferredExtensionDto::class);

    expect($dto->value)->toBe('from-service-method')
        ->and($dto->fallback)->toBe('from-deferred-resolver');
});

test('config accepts the same deferred extension service method forms', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                DeferredExtensionFactoryService::class => new DeferredExtensionFactoryService(),
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                [DeferredExtensionFactoryService::class, 'attribute'],
            ],
            ConfigKey::PARAMETER_RESOLVERS => [
                750 => [DeferredExtensionFactoryService::class, 'valueResolver'],
                350 => [DeferredExtensionFactoryService::class, 'conventionResolver'],
            ],
        ],
    ]);

    $dto = ContainerBuilder::configure($config)->build()->make(DeferredExtensionDto::class);

    expect($dto->value)->toBe('from-service-method')
        ->and($dto->fallback)->toBe('from-deferred-resolver');
});
