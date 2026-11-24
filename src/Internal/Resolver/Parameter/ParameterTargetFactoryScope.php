<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

/** @internal */
abstract class ParameterTargetFactoryScope
{
    protected function __construct(protected readonly ParameterTargets $targets) {}

    private function __clone() {}
}
