<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;

final class MakeTargets
{
    #[Make]
    public SimpleService $typeDerived;

    #[Make(ServiceWithParam::class, params: ['value' => 'make-params'])]
    public ServiceWithParam $explicitWithParams;

    #[Make, Proxy]
    public SimpleService $withProxy;

    public SimpleService $unattributed;

    public function byParameters(
        #[Make] SimpleService $typeDerived,
        #[Make(ServiceWithParam::class, params: ['value' => 'param-make'])] ServiceWithParam $withParams,
        SimpleService $plain,
    ): void {}
}
