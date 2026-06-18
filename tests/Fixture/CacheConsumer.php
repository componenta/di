<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class CacheConsumer
{
    public function __construct(
        public SimpleService $service,
    ) {}
}
