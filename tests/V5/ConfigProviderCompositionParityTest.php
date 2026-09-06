<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final readonly class ProviderResolverA implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'composed';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target) ? [$target->position, 'provider-a'] : null;
    }
}

final readonly class ProviderResolverB implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'composed';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target) ? [$target->position, 'provider-b'] : null;
    }
}

final readonly class ProviderComposedTarget
{
    public function __construct(public string $composed) {}
}

final readonly class ProviderFactoryProduct
{
    public function __construct(public string $origin) {}
}

final readonly class ProviderFirstFactoryProduct {}

final readonly class ProviderSecondFactoryProduct {}

final readonly class ProviderFirstInvokable {}

final readonly class ProviderSecondInvokable {}

final readonly class ProviderBuiltInDefaultsTarget
{
    public function __construct(
        public ProviderFirstInvokable $dependency,
        #[ConfigAttribute(new ConfigPath('provider.built_in'))]
        public string $configured,
    ) {}
}

interface ProviderMergeExclusiveCapability extends AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProviderMergeAttributeA {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProviderMergeAttributeB {}

#[ProviderMergeAttributeA, ProviderMergeAttributeB]
final readonly class ProviderMergeAttributeTarget {}

test('ordered provider composition survives the complete DI consumer boundary', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'provider' => [
                'built_in' => 'available',
            ],
            ConfigKey::DEPENDENCIES => [
                ConfigKey::SERVICES => [
                    'atomic.service' => 'provider-a',
                    'alias.first' => 'first',
                    'alias.second' => 'second',
                    'decorated.service' => 'base',
                ],
                ConfigKey::ALIASES => [
                    'composed.alias' => 'alias.first',
                ],
                ConfigKey::DELEGATORS => [
                    'decorated.service' => [
                        static fn(string $entry): string => $entry . ':a',
                    ],
                ],
                ConfigKey::PARAMETER_RESOLVERS => [
                    700 => new ProviderResolverA(),
                ],
                ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
                ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => true,
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::SERVICES => [
                    'atomic.service' => 'provider-b',
                ],
                ConfigKey::ALIASES => [
                    'composed.alias' => 'alias.second',
                ],
                ConfigKey::DELEGATORS => [
                    'decorated.service' => [
                        static fn(string $entry): string => $entry . ':b',
                    ],
                ],
                ConfigKey::PARAMETER_RESOLVERS => [
                    700 => new ProviderResolverB(),
                ],
                ConfigKey::PARAMETER_RESOLVERS_REPLACE => false,
                ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => false,
            ],
        ],
    );

    $container = (new ContainerFactory())->create(
        $composition->config,
        $composition->dependencies,
    )->container;

    if (!$container instanceof \Componenta\DI\Container) {
        throw new \LogicException('ContainerFactory returned an unsupported container implementation.');
    }
    $builtInDefaults = $container->make(ProviderBuiltInDefaultsTarget::class);

    expect($composition->dependencies->sections[ConfigKey::PARAMETER_RESOLVERS_REPLACE])
        ->toBeFalse()
        ->and($composition->dependencies->sections[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])
        ->toBeFalse()
        ->and($composition->config->has(ConfigKey::DEPENDENCIES))->toBeFalse()
        ->and($builtInDefaults->dependency)->toBeInstanceOf(ProviderFirstInvokable::class)
        ->and($builtInDefaults->configured)->toBe('available')
        ->and($container->get('atomic.service'))->toBe('provider-b')
        ->and($container->get('composed.alias'))->toBe('second')
        ->and($container->get('decorated.service'))->toBe('base:a:b')
        ->and($container->make(ProviderComposedTarget::class)->composed)->toBe('provider-b');
});

test('provider merge preserves keyed overrides and numeric invokable order at the DI boundary', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    ProviderFactoryProduct::class => static fn(): ProviderFactoryProduct => new ProviderFactoryProduct('a'),
                    ProviderFirstFactoryProduct::class => static fn(): ProviderFirstFactoryProduct => new ProviderFirstFactoryProduct(),
                ],
                ConfigKey::INVOKABLES => [
                    'selected.invokable' => ProviderFirstInvokable::class,
                    ProviderFirstInvokable::class,
                ],
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    ProviderFactoryProduct::class => static fn(): ProviderFactoryProduct => new ProviderFactoryProduct('b'),
                    ProviderSecondFactoryProduct::class => static fn(): ProviderSecondFactoryProduct => new ProviderSecondFactoryProduct(),
                ],
                ConfigKey::INVOKABLES => [
                    'selected.invokable' => ProviderSecondInvokable::class,
                    ProviderSecondInvokable::class,
                ],
            ],
        ],
    );

    $container = (new ContainerFactory())->create(
        $composition->config,
        $composition->dependencies,
    )->container;

    if (!$container instanceof \Componenta\DI\Container) {
        throw new \LogicException('ContainerFactory returned an unsupported container implementation.');
    }

    expect($container->get(ProviderFactoryProduct::class)->origin)->toBe('b')
        ->and($container->get(ProviderFirstFactoryProduct::class))->toBeInstanceOf(ProviderFirstFactoryProduct::class)
        ->and($container->get(ProviderSecondFactoryProduct::class))->toBeInstanceOf(ProviderSecondFactoryProduct::class)
        ->and($container->get('selected.invokable'))->toBeInstanceOf(ProviderSecondInvokable::class)
        ->and($container->get(ProviderFirstInvokable::class))->toBeInstanceOf(ProviderFirstInvokable::class)
        ->and($container->get(ProviderSecondInvokable::class))->toBeInstanceOf(ProviderSecondInvokable::class);
});

test('provider merge appends attribute definitions and capabilities before DI seals the pipeline', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ATTRIBUTE_CAPABILITIES => [
                    new CapabilityPolicy(ProviderMergeExclusiveCapability::class, 1),
                ],
                ConfigKey::ATTRIBUTE_DEFINITIONS => [
                    new AttributeDefinition(
                        ProviderMergeAttributeA::class,
                        capabilities: [ProviderMergeExclusiveCapability::class],
                    ),
                ],
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ATTRIBUTE_DEFINITIONS => [
                    new AttributeDefinition(
                        ProviderMergeAttributeB::class,
                        capabilities: [ProviderMergeExclusiveCapability::class],
                    ),
                ],
            ],
        ],
    );

    $container = (new ContainerFactory())->create(
        $composition->config,
        $composition->dependencies,
    )->container;

    if (!$container instanceof \Componenta\DI\Container) {
        throw new \LogicException('ContainerFactory returned an unsupported container implementation.');
    }

    expect(fn() => $container->make(ProviderMergeAttributeTarget::class))
        ->toThrow(AttributeCompositionException::class, 'accepts at most 1 attribute');
});
