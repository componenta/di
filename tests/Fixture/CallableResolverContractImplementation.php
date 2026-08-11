<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class CallableResolverContractImplementation implements CallableResolverContract
{
    public function handle(int $value): string
    {
        return 'contract:' . $value;
    }
}
