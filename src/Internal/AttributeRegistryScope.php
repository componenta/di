<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

/** @internal */
abstract class AttributeRegistryScope
{
    protected function __construct(protected readonly AttributeDefinitions $definitions) {}

    private function __clone() {}
}
