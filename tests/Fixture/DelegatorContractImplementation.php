<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Psr\Container\ContainerInterface;

final class DelegatorContractImplementation implements DelegatorContract
{
    public function decorate(mixed $entry, ContainerInterface $container): mixed
    {
        return is_string($entry) ? $entry . ':decorated' : $entry;
    }
}
