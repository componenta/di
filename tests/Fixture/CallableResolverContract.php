<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

interface CallableResolverContract
{
    public function handle(int $value): string;
}
