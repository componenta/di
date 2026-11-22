<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

/** @internal */
abstract class ParameterExecutionScope
{
    protected function __construct(protected readonly ParameterExecution $execution) {}

    private function __clone() {}
}
