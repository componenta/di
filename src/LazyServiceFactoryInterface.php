<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Container\ContainerInterface;

/** Factory entry that owns creation of its lazy wrapper. */
interface LazyServiceFactoryInterface
{
    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ResolutionContext $context = new ResolutionContext(),
    ): object;
}
