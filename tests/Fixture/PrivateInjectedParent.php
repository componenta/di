<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Inject;

abstract class PrivateInjectedParent
{
    #[Inject]
    private PrivateInjectedDependency $dependency;

    public function dependency(): PrivateInjectedDependency
    {
        return $this->dependency;
    }
}
