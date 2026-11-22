<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

/** @internal */
abstract class ObjectAttributeScope
{
    protected function __construct(protected readonly AttributeExecution $execution) {}

    private function __clone() {}
}
