<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Lazy;

#[Lazy]
final class PrivateLazyWithDependency
{
    public bool $initialized;

    private function __construct(
        public SimpleService $dependency,
    ) {
        $this->initialized = true;
    }
}
