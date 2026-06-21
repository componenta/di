<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Container\ContainerInterface;

/**
 * Contract for factory entries that create their own lazy wrapper.
 *
 * Factory entries do not use class-level #[Lazy] / #[Proxy] metadata.
 */
interface LazyServiceFactoryInterface
{
    /**
     * @param ContainerInterface $container Container for resolving dependencies.
     * @param ProxyFactoryInterface $proxyFactory Lazy object / virtual proxy factory.
     * @param array<string|int, mixed> $context Resolution context forwarded by
     *                                          {@see FactoryInterface::make()}.
     *
     * @return object Lazy wrapper compatible with the service type.
     */
    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object;
}