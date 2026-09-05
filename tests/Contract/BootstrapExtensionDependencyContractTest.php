<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Psr\Container\ContainerInterface;
use Reflector;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_CLASS)]
final class BootstrapReady {}

#[Attribute(Attribute::TARGET_CLASS)]
final class BootstrapConsumer {}

#[BootstrapReady]
final class BootstrapDependency
{
    public bool $ready = false;
}

#[BootstrapConsumer]
final class BootstrapConsumerTarget
{
    public bool $ready = false;
}

final class BootstrapReadyHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($context->entry instanceof BootstrapDependency) {
            $context->entry->ready = true;
        }
    }
}

final readonly class BootstrapConsumerHandler implements AttributeHandlerInterface
{
    public function __construct(private BootstrapDependency $dependency) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($context->entry instanceof BootstrapConsumerTarget) {
            $context->entry->ready = $this->dependency->ready;
        }
    }
}

it('rejects bootstrap dependencies created before their attribute extension is registered', function (): void {
    expect(fn() => container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static fn(Container $container): AttributeDefinition => new AttributeDefinition(
                BootstrapConsumer::class,
                $container->get(BootstrapConsumerHandler::class),
                [LifecycleHook::class],
            ),
            new AttributeDefinition(BootstrapReady::class, new BootstrapReadyHandler(), [LifecycleHook::class]),
        ],
    ]))->toThrow(InvalidConfigurationException::class, BootstrapDependency::class);
});

final readonly class BootstrapConventionDependency
{
    public function __construct(public string $bootstrapValue = 'fallback') {}
}

final class BootstrapConventionResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
{
    public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
    {
        return $target->name === 'bootstrapValue';
    }

    public function resolveParameter(
        \Componenta\DI\Resolver\Target\ParameterTarget $target,
        \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
    ): array {
        return [$target->position, 'resolved'];
    }
}

it('rejects bootstrap parameters consumed before an applicable earlier resolver is registered', function (): void {
    expect(fn() => container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (ContainerInterface $container): AttributeDefinition {
                $container->get(BootstrapConventionDependency::class);
                return new AttributeDefinition(BootstrapConsumer::class);
            },
        ],
        ConfigKey::PARAMETER_RESOLVERS => [
            500 => new BootstrapConventionResolver(),
        ],
    ]))->toThrow(InvalidConfigurationException::class, BootstrapConventionDependency::class);
});

it('initializes bootstrap dependencies when their attribute extension is registered first', function (): void {
    $container = container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(BootstrapReady::class, new BootstrapReadyHandler(), [LifecycleHook::class]),
            static fn(Container $container): AttributeDefinition => new AttributeDefinition(
                BootstrapConsumer::class,
                $container->get(BootstrapConsumerHandler::class),
                [LifecycleHook::class],
            ),
        ],
    ]);

    expect($container->make(BootstrapConsumerTarget::class)->ready)->toBeTrue()
        ->and($container->get(BootstrapDependency::class)->ready)->toBeTrue();
});

it('permits a later resolver whose priority cannot intercept an already resolved parameter', function (): void {
    $container = container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (ContainerInterface $container): AttributeDefinition {
                $container->get(BootstrapConventionDependency::class);
                return new AttributeDefinition(BootstrapConsumer::class);
            },
        ],
        ConfigKey::PARAMETER_RESOLVERS => [
            100 => new BootstrapConventionResolver(),
        ],
    ]);

    expect($container->get(BootstrapConventionDependency::class)->bootstrapValue)->toBe('fallback')
        ->and($container->make(BootstrapConventionDependency::class)->bootstrapValue)->toBe('fallback');
});

it('permits later extensions unrelated to bootstrap dependencies and metadata only inspection', function (): void {
    $container = container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (ContainerInterface $container): AttributeDefinition {
                $container->get(BootstrapConsumerHandler::class);
                $container->has(BootstrapConventionDependency::class);
                return new AttributeDefinition(BootstrapConsumer::class);
            },
        ],
        ConfigKey::PARAMETER_RESOLVERS => [
            500 => new BootstrapConventionResolver(),
        ],
    ]);

    expect($container->get(BootstrapConsumerHandler::class))->toBeInstanceOf(BootstrapConsumerHandler::class)
        ->and($container->make(BootstrapConventionDependency::class)->bootstrapValue)->toBe('resolved');
});

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BootstrapParameterValue {}

final readonly class BootstrapParameterDependency
{
    public string $value;

    public function __construct(#[BootstrapParameterValue] string $value = 'fallback')
    {
        $this->value = $value;
    }
}

final class BootstrapParameterHandler implements \Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        \Componenta\DI\Resolver\Target\ParameterTarget $target,
        \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        \Componenta\DI\Attribute\Composition\AttributePlan $plan,
        \Componenta\DI\Resolver\Parameter\ParameterAttributeValue $value,
    ): \Componenta\DI\Resolver\Parameter\ParameterAttributeValue {
        return \Componenta\DI\Resolver\Parameter\ParameterAttributeValue::resolved('attribute');
    }
}

it('rejects constructor parameters resolved before their value attribute is registered', function (): void {
    expect(fn() => container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (ContainerInterface $container): AttributeDefinition {
                $container->get(BootstrapParameterDependency::class);
                return new AttributeDefinition(BootstrapConsumer::class);
            },
            new AttributeDefinition(
                BootstrapParameterValue::class,
                new BootstrapParameterHandler(),
                [\Componenta\DI\Attribute\Composition\Capability\ValueProvider::class],
            ),
        ],
    ]))->toThrow(InvalidConfigurationException::class, BootstrapParameterDependency::class);
});
