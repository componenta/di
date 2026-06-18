<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Psr\Container\ContainerExceptionInterface;
use Throwable;

/**
 * Root marker interface for all exceptions thrown by the DI container.
 *
 * Extends {@see ContainerExceptionInterface} so that any DI-container failure
 * is also a PSR-11 container failure - a single catch of either interface
 * captures every value the container can raise.
 *
 * @see NotFoundException               Entry lookup failed (PSR-11 NotFound).
 * @see CircularDependencyException     Cycle detected in a service or alias graph.
 * @see InvalidConfigurationException   Configuration or definition invalid.
 * @see ResolutionException             Autowire / factory / property failure.
 * @see DelegatorException              Delegator (decorator) misbehaved.
 * @see CallableExceptionInterface      Anything in the callable pipeline.
 */
interface ExceptionInterface extends Throwable, ContainerExceptionInterface
{
}
