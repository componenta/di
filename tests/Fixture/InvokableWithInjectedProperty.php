<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Inject;

final class InvokableWithInjectedProperty
{
    #[Inject]
    public ReplacementFactoryService $dependency;
}
