<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class ServiceWithParam
{
    public function __construct(public string $value) {}
}
