<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;

/** Separates the public view from the values and origin used by DI. @internal */
abstract class ConstructionParameterScope
{
    /**
     * @param array<string|int,mixed> $arguments
     * @param array<string|int,mixed> $factoryValues
     */
    final protected function __construct(
        protected readonly array $arguments,
        protected readonly array $factoryValues,
        protected readonly ?MappedRequestContext $provenance,
    ) {}
}
