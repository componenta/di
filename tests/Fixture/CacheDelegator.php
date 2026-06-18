<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class CacheDelegator
{
    public function __invoke(ServiceWithParam $service): ServiceWithParam
    {
        return new ServiceWithParam($service->value . '-decorated');
    }
}
