<?php

declare(strict_types=1);

use Componenta\Caster\ConfigProvider as CasterConfigProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\ConfigProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class ConfigProviderInvokableDefinitionFixture {}

final class ConfigProviderCurrentUserFixture {}

it('wires optional DI behavior when composed with its dependency providers', function (): void {
    $provider = new class () extends \Componenta\Config\ConfigProvider {
        protected function getProviders(): array
        {
            return [
                new CasterConfigProvider(),
                new ConfigProvider(),
            ];
        }
    };
    $container = ContainerBuilder::configure(new Config($provider()))->build();
    $user = new ConfigProviderCurrentUserFixture();
    $currentUser = $container->get(CurrentUserProviderInterface::class);
    $currentUser->setUser($user);
    $request = new FakeServerRequest(queryParams: ['limit' => '12']);

    $resolved = $container->call(static fn(
        #[Cast('int')] int $count,
        #[CurrentUser] ConfigProviderCurrentUserFixture $authenticated,
        #[QueryParam('limit', cast: 'int')] int $limit,
    ): array => [$count, $authenticated, $limit], [
        'count' => '7',
        ServerRequestInterface::class => $request,
    ]);

    expect($resolved)->toBe([7, $user, 12]);
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
