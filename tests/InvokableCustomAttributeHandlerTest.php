<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Fixture\CustomInvokableLifecycle;
use Componenta\DI\Tests\Fixture\InvokableWithCustomLifecycle;

function customInvokableLifecycleHandler(): AttributeHandlerInterface
{
    return new class () implements AttributeHandlerInterface {
        public AttributePhase $phase {
            get => AttributePhase::AfterInstantiation;
        }

        public int $priority {
            get => 0;
        }

        public function supportsAttribute(string $attributeClass, Reflector $target): bool
        {
            return $target instanceof ReflectionClass
                && is_a($attributeClass, CustomInvokableLifecycle::class, true);
        }

        public function handle(
            object $attribute,
            Reflector $target,
            ObjectCreationContext $context,
        ): void {}
    };
}

it('rejects configured invokables handled by a custom attribute lifecycle extension', function (): void {
    $builder = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::INVOKABLES => [InvokableWithCustomLifecycle::class],
            ConfigKey::ATTRIBUTE_HANDLERS => [customInvokableLifecycleHandler()],
        ],
    );

    expect(fn() => $builder->build())
        ->toThrow(InvalidConfigurationException::class, 'attribute lifecycle');
});

it('rejects runtime invokable definitions handled by a custom attribute lifecycle extension', function (): void {
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::ATTRIBUTE_HANDLERS => [customInvokableLifecycleHandler()],
        ],
    )->build();

    expect(fn() => $container->set(
        'service',
        Definition::invokable(InvokableWithCustomLifecycle::class),
    ))->toThrow(InvalidConfigurationException::class, 'attribute lifecycle');
});
