<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Container\ContainerInterface;

/** Factory entry that owns creation of its lazy wrapper. */
interface LazyServiceFactoryInterface
{
    /** @param array<string|int, mixed> $context */
    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object;
}
