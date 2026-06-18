<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;

/**
 * Optional resolver registrations that the bare {@see ContainerBuilder} no
 * longer ships with. Include this provider in the application's config chain
 * to enable `#[Cast]`, `#[CurrentUser]`, and the PSR-7 attribute family
 * (`#[Header]`, `#[QueryParam]`, `Map*`, ...).
 *
 * The provider also installs a no-op {@see CurrentUserProvider} default so
 * apps that don't wire their own user provider still satisfy the resolver's
 * dependency.
 */
final class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            CurrentUserProviderInterface::class => static fn (): CurrentUserProviderInterface
                => new CurrentUserProvider(),
        ];
    }

    protected function getParameterResolvers(): array
    {
        return [
            ContainerBuilder::PRIORITY_PARAM_CASTABLE     => CastableResolver::class,
            ContainerBuilder::PRIORITY_PARAM_CURRENT_USER => CurrentUserResolver::class,
            ContainerBuilder::PRIORITY_PARAM_REQUEST      => RequestResolver::class,
        ];
    }

    protected function getPropertyResolvers(): array
    {
        return [
            ContainerBuilder::PRIORITY_PROP_CASTABLE     => CastableResolver::class,
            ContainerBuilder::PRIORITY_PROP_CURRENT_USER => CurrentUserResolver::class,
        ];
    }
}
