<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;

final class ConfigProviderInvokableDefinitionFixture {}

it('registers the optional DI extension pipeline and its factories', function () {
    $config = (new ConfigProvider())();
    $dependencies = $config[ConfigKey::DEPENDENCIES];
    $diDependencyKeys = \Componenta\DI\ConfigKey::dependencyKeys();

    expect($dependencies[ConfigKey::FACTORIES][RequestResolver::class] ?? null)
        ->toBe(RequestResolverFactory::class)
        ->and($dependencies[\Componenta\DI\ConfigKey::PARAMETER_RESOLVERS])
        ->toBe([
            ContainerBuilder::PRIORITY_PARAM_CASTABLE => CastableResolver::class,
            ContainerBuilder::PRIORITY_PARAM_CURRENT_USER => CurrentUserResolver::class,
            ContainerBuilder::PRIORITY_PARAM_REQUEST => RequestResolver::class,
        ])
        ->and($dependencies[\Componenta\DI\ConfigKey::ATTRIBUTE_HANDLERS])
        ->toBe([CastableResolver::class, CurrentUserResolver::class])
        ->and(array_diff($diDependencyKeys, ConfigKey::dependencyKeys()))
        ->toBe([])
        ->and($diDependencyKeys)
        ->not->toContain('generated_entry_resolver_file')
        ->and($diDependencyKeys)
        ->not->toContain('generated_entry_resolver_release_fingerprint');

    $container = ContainerBuilder::configure(new Config($config))
        ->addService(CasterProviderInterface::class, new NullCasterProvider())
        ->build();

    expect($container->get(CurrentUserProviderInterface::class))
        ->toBeInstanceOf(CurrentUserProvider::class)
        ->and($container->get(RequestResolver::class))
        ->toBeInstanceOf(RequestResolver::class);
});

it('accepts factory definitions directly from a config provider factories section', function (): void {
    $provider = new class () extends \Componenta\Config\ConfigProvider {
        protected function getFactories(): array
        {
            return [
                'provider.definition' => Definition::factory(
                    static fn() => (object) ['source' => 'definition'],
                ),
            ];
        }
    };

    $container = ContainerBuilder::configure(new Config($provider()))->build();

    expect($container->get('provider.definition')->source)->toBe('definition');
});

it('accepts invokable definitions directly from a config provider invokables section', function (): void {
    $provider = new class () extends \Componenta\Config\ConfigProvider {
        protected function getInvokables(): array
        {
            return [
                Definition::invokable(ConfigProviderInvokableDefinitionFixture::class),
                'provider.invokable.alias' => Definition::invokable(
                    ConfigProviderInvokableDefinitionFixture::class,
                ),
            ];
        }
    };

    $container = ContainerBuilder::configure(new Config($provider()))->build();

    expect($container->get(ConfigProviderInvokableDefinitionFixture::class))
        ->toBeInstanceOf(ConfigProviderInvokableDefinitionFixture::class)
        ->and($container->get('provider.invokable.alias'))
        ->toBeInstanceOf(ConfigProviderInvokableDefinitionFixture::class);
});
