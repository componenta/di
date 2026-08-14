<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;

it('registers the lazy request resolver factory', function () {
    $config = (new ConfigProvider())();

    $dependencies = $config[ConfigKey::DEPENDENCIES];
    $diDependencyKeys = \Componenta\DI\ConfigKey::dependencyKeys();

    expect($dependencies[ConfigKey::FACTORIES][RequestResolver::class] ?? null)
        ->toBe(RequestResolverFactory::class)
        ->and($dependencies[ConfigKey::ATTRIBUTE_HANDLERS])
        ->toBe([CastableResolver::class, CurrentUserResolver::class])
        ->and(array_diff($diDependencyKeys, ConfigKey::dependencyKeys()))
        ->toBe([])
        ->and($diDependencyKeys)
        ->not->toContain('generated_entry_resolver_file')
        ->and($diDependencyKeys)
        ->not->toContain('generated_entry_resolver_release_fingerprint');
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
