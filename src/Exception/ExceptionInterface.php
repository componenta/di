<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Psr\Container\ContainerExceptionInterface;
use Throwable;

/**
 * Root marker for failures owned or normalized by Componenta DI.
 *
 * Every failure produced while Componenta DI resolves, creates, configures,
 * compiles or decorates an entry is surfaced through this interface. Foreign
 * throwables are retained as the previous exception of an appropriate DI
 * exception.
 *
 * The one deliberate boundary is an explicitly invoked user callable: once
 * DI has resolved the callable and all of its arguments and control enters the
 * callable body, throwables raised by that body propagate unchanged.
 *
 * Extending PSR-11 ContainerExceptionInterface gives consumers both a
 * Componenta-specific catch boundary and normal PSR-11 interoperability.
 */
interface ExceptionInterface extends Throwable, ContainerExceptionInterface {}
