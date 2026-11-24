<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

/** @internal */
abstract class ParameterAttributeScope
{
    protected function __construct(protected readonly AttributeParameterExecution $execution) {}

    private function __clone() {}
}
