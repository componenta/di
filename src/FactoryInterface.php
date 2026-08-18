<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;

/** Creates fresh object instances through the DI resolution pipeline. */
interface FactoryInterface
{
    /**
     * @param class-string|non-empty-string $entry
     * @throws NotFoundException
     * @throws CircularDependencyException
     * @throws ResolutionException
     */
    public function make(
        string $entry,
        ResolutionContext $context = new ResolutionContext(),
    ): object;
}
