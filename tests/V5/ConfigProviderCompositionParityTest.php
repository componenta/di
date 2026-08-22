<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
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

test('ConfigLoader provider composition survives the complete DI consumer boundary', function (): void {
    $config = ConfigLoader::load(
        new Environment([]),
        static fn(): array => [
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
            ],
        ],
    );

    $builder = ContainerBuilder::configure($config);
    $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
    $container = $builder->build();

    expect($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE])->toBeFalse()
        ->and($container->get('atomic.service'))->toBe('provider-b')
        ->and($container->get('composed.alias'))->toBe('second')
        ->and($container->get('decorated.service'))->toBe('base:a:b')
        ->and($container->make(ProviderComposedTarget::class)->composed)->toBe('provider-b');
});
