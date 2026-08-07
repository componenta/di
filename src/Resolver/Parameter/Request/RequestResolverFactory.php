<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Psr\Container\ContainerInterface;

/** Creates RequestResolver without eagerly resolving optional subsystems. */
final readonly class RequestResolverFactory
{
    public function __invoke(ContainerInterface $container): RequestResolver
    {
        return new RequestResolver(
            new LazyFactory($container),
            new LazyCasterProvider($container),
            new LazyValidationProvider($container),
        );
    }
}
