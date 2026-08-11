<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class InvokableWithOptionalDependency
{
    public function __construct(
        public ?ReplacementFactoryService $dependency = null,
    ) {}
}
