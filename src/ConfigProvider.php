<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestSourceConflictResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;

/** Optional parameter resolvers and attribute handlers. */
final class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            CurrentUserProviderInterface::class
                => static fn(): CurrentUserProviderInterface => new CurrentUserProvider(),
            RequestResolver::class => RequestResolverFactory::class,
        ];
    }

    protected function getParameterResolvers(): array
    {
        return [
            MappedRequestSourceConflictResolver::PRIORITY => MappedRequestSourceConflictResolver::class,
            ContainerBuilder::PRIORITY_PARAM_CASTABLE => CastableResolver::class,
            ContainerBuilder::PRIORITY_PARAM_CURRENT_USER => CurrentUserResolver::class,
            ContainerBuilder::PRIORITY_PARAM_REQUEST => RequestResolver::class,
        ];
    }

    protected function getAttributeHandlers(): array
    {
        return [
            CastableResolver::class,
            CurrentUserResolver::class,
        ];
    }
}
