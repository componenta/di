<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

/** @internal */
abstract class ObjectPipelineScope
{
    protected function __construct(protected readonly ObjectCreation $creation) {}

    private function __clone() {}
}
