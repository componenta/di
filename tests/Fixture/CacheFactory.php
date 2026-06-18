<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Psr\Container\ContainerInterface;

final class CacheFactory
{
    public function __invoke(ContainerInterface $container): ServiceWithParam
    {
        return new ServiceWithParam($container->get('cache.value'));
    }
}
