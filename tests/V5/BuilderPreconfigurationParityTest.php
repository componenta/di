<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

interface PreconfiguredCapability extends AttributeCapabilityInterface {}
interface ConfiguredCapability extends AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PreconfiguredAttribute {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ConfiguredAttribute {}

final class FullyPreconfiguredBuilder extends ContainerBuilder
{
    public function __construct()
    {
        parent::__construct();

        $this->addService('preconfigured.service', 'constructor');
        $this->addService('shared.service', 'constructor');
        $this->addService('decorated.service', 'base');
        $this->addDelegator(
            'decorated.service',
            static fn(string $entry): string => $entry . ':constructor',
        );
        $this->addAttributeDefinition(new AttributeDefinition(PreconfiguredAttribute::class));
        $this->defineAttributeCapability(new CapabilityPolicy(PreconfiguredCapability::class, 1));
        $this->replaceParameterResolvers();
        $this->replaceAttributeDefinitions();
    }
}

test('configuration overlays rather than discards subclass preconfiguration', function (): void {
    $builder = FullyPreconfiguredBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::SERVICES => [
                'shared.service' => 'configured',
                'configured.service' => 'ready',
            ],
            ConfigKey::DELEGATORS => [
                'decorated.service' => [
                    static fn(string $entry): string => $entry . ':configured',
                ],
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                new AttributeDefinition(ConfiguredAttribute::class),
            ],
            ConfigKey::ATTRIBUTE_CAPABILITIES => [
                new CapabilityPolicy(ConfiguredCapability::class, 1),
            ],
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => false,
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => false,
        ],
    );

    $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
    expect($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE])->toBeFalse()
        ->and($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])->toBeFalse();

    $container = $builder->build();
    $registry = $container->get(AttributeDefinitionRegistry::class);

    expect($container->get('preconfigured.service'))->toBe('constructor')
        ->and($container->get('shared.service'))->toBe('configured')
        ->and($container->get('configured.service'))->toBe('ready')
        ->and($container->get('decorated.service'))->toBe('base:constructor:configured')
        ->and($registry)->toBeInstanceOf(AttributeDefinitionRegistry::class)
        ->and($registry->definition(PreconfiguredAttribute::class))->not->toBeNull()
        ->and($registry->definition(ConfiguredAttribute::class))->not->toBeNull()
        ->and($registry->policy(PreconfiguredCapability::class)->maxPerTarget)->toBe(1)
        ->and($registry->policy(ConfiguredCapability::class)->maxPerTarget)->toBe(1);
});
