<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class InvokableService
{
    public function __invoke(string $suffix = ''): string
    {
        return 'invoked' . $suffix;
    }
}
