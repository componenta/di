<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackDefinition;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;
use Psr\Container\ContainerInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class DeferredValue {}

final readonly class DeferredValueHandler implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence { get => ValueProviderPrecedence::ProviderFirst; }

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        return 'from-service-method';
    }
}

final readonly class DeferredFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return $target->name === 'fallback';
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        return new ValueResult('from-deferred-fallback');
    }
}

final readonly class DeferredExtensionFactoryService
{
    public function attribute(ContainerInterface $container): AttributeDefinition
    {
        return new AttributeDefinition(
            DeferredValue::class,
            new DeferredValueHandler(),
            [ValueProvider::class],
        );
    }

    public function fallback(ContainerInterface $container): ValueFallbackDefinition
    {
        return new ValueFallbackDefinition(
            'deferred-extension-fallback',
            new DeferredFallback(),
            before: ['property_initial'],
        );
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

test('builder materializes deferred extension service methods', function (): void {
    $container = (new ContainerBuilder())
        ->addService(DeferredExtensionFactoryService::class, new DeferredExtensionFactoryService())
        ->addAttributeDefinition([DeferredExtensionFactoryService::class, 'attribute'])
        ->addValueFallback([DeferredExtensionFactoryService::class, 'fallback'])
        ->build();

    $dto = $container->make(DeferredExtensionDto::class);

    expect($dto->value)->toBe('from-service-method')
        ->and($dto->fallback)->toBe('from-deferred-fallback');
});

test('config accepts the same deferred extension service method form', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                DeferredExtensionFactoryService::class => new DeferredExtensionFactoryService(),
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                [DeferredExtensionFactoryService::class, 'attribute'],
            ],
            ConfigKey::VALUE_FALLBACKS => [
                [DeferredExtensionFactoryService::class, 'fallback'],
            ],
        ],
    ]);

    $dto = ContainerBuilder::configure($config)->build()->make(DeferredExtensionDto::class);

    expect($dto->value)->toBe('from-service-method')
        ->and($dto->fallback)->toBe('from-deferred-fallback');
});
