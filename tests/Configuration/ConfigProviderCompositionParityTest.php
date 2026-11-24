<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Configuration\ConfigProviderCompositionParity;

use Attribute;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use PHPUnit\Framework\Assert;

use function Componenta\DI\Tests\Support\fixtureInstance;

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

final readonly class ProviderFirstFactoryProduct
{
    public function __construct(public string $origin) {}
}

final readonly class ProviderSecondFactoryProduct
{
    public function __construct(public string $origin) {}
}

#[SetUp('configure')]
final class ProviderFirstInvokable
{
    public string $origin = 'invokable';

    public function configure(): void
    {
        $this->origin = 'autowired';
    }
}

#[SetUp('configure')]
final class ProviderSecondInvokable
{
    public string $origin = 'invokable';

    public function configure(): void
    {
        $this->origin = 'autowired';
    }
}

final readonly class ProviderBuiltInDefaultsTarget
{
    public function __construct(
        public ProviderFirstInvokable $dependency,
        #[ConfigAttribute(new ConfigPath('provider.built_in'))]
        public string $configured,
    ) {}
}

interface ProviderMergeExclusiveCapability extends AttributeCapabilityInterface {}
interface ProviderMergeSecondaryCapability extends AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProviderMergeAttributeA {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProviderMergeAttributeB {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProviderMergeAttributeC {}

#[ProviderMergeAttributeA, ProviderMergeAttributeB]
final readonly class ProviderMergeAttributeTarget {}

#[ProviderMergeAttributeB, ProviderMergeAttributeC]
final readonly class ProviderMergeSecondaryTarget {}

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
    Assert::assertInstanceOf(ProviderBuiltInDefaultsTarget::class, $builtInDefaults);

    expect($composition->dependencies->sections[ConfigKey::PARAMETER_RESOLVERS_REPLACE])
        ->toBeFalse()
        ->and($composition->dependencies->sections[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])
        ->toBeFalse()
        ->and($composition->config->has(ConfigKey::DEPENDENCIES))->toBeFalse()
        ->and($builtInDefaults->dependency->origin)->toBe('autowired')
        ->and($builtInDefaults->configured)->toBe('available')
        ->and($container->get('atomic.service'))->toBe('provider-b')
        ->and($container->get('composed.alias'))->toBe('second')
        ->and($container->get('decorated.service'))->toBe('base:a:b')
        ->and(fixtureInstance($container->make(ProviderComposedTarget::class), ProviderComposedTarget::class)->composed)->toBe('provider-b');
});

test('provider merge preserves keyed overrides and numeric invokable order at the DI boundary', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    ProviderFactoryProduct::class => static fn(): ProviderFactoryProduct => new ProviderFactoryProduct('a'),
                    ProviderFirstFactoryProduct::class => static fn(): ProviderFirstFactoryProduct => new ProviderFirstFactoryProduct('first-factory'),
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
                    ProviderSecondFactoryProduct::class => static fn(): ProviderSecondFactoryProduct => new ProviderSecondFactoryProduct('second-factory'),
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

    $first = fixtureInstance($container->get(ProviderFirstInvokable::class), ProviderFirstInvokable::class);
    $second = fixtureInstance($container->get(ProviderSecondInvokable::class), ProviderSecondInvokable::class);
    $invokables = $composition->dependencies->sections[ConfigKey::INVOKABLES] ?? null;
    if (!is_array($invokables)) {
        throw new \LogicException('Expected composed invokable registrations.');
    }

    expect(fixtureInstance($container->get(ProviderFactoryProduct::class), ProviderFactoryProduct::class)->origin)->toBe('b')
        ->and(fixtureInstance($container->get(ProviderFirstFactoryProduct::class), ProviderFirstFactoryProduct::class)->origin)->toBe('first-factory')
        ->and(fixtureInstance($container->get(ProviderSecondFactoryProduct::class), ProviderSecondFactoryProduct::class)->origin)->toBe('second-factory')
        ->and($container->get('selected.invokable'))->toBe($second)
        ->and($first->origin)->toBe('invokable')
        ->and($second->origin)->toBe('invokable')
        ->and(array_values(array_filter(
            $invokables,
            static fn(int|string $key): bool => is_int($key),
            ARRAY_FILTER_USE_KEY,
        )))->toBe([ProviderFirstInvokable::class, ProviderSecondInvokable::class]);
});

test('DI enforces composed attribute definitions and capability limits from both providers', function (): void {
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
                    new AttributeDefinition(
                        ProviderMergeAttributeC::class,
                        capabilities: [ProviderMergeSecondaryCapability::class],
                    ),
                ],
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ATTRIBUTE_CAPABILITIES => [
                    new CapabilityPolicy(ProviderMergeSecondaryCapability::class, 1),
                ],
                ConfigKey::ATTRIBUTE_DEFINITIONS => [
                    new AttributeDefinition(
                        ProviderMergeAttributeB::class,
                        capabilities: [ProviderMergeExclusiveCapability::class, ProviderMergeSecondaryCapability::class],
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
        ->toThrow(AttributeCompositionException::class, 'accepts at most 1 attribute(s) with capability ' . ProviderMergeExclusiveCapability::class)
        ->and(fn() => $container->make(ProviderMergeSecondaryTarget::class))
        ->toThrow(AttributeCompositionException::class, 'accepts at most 1 attribute(s) with capability ' . ProviderMergeSecondaryCapability::class);
});
