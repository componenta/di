<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Psr\Container\ContainerInterface;

interface DelegatorContract
{
    public function decorate(mixed $entry, ContainerInterface $container): mixed;
}
