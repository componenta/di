<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Capability;

/**
 * Marks a contextual value source that is valid only for callable invocation.
 *
 * Constructor injection is rejected because the resolved value would become
 * object state and could outlive the execution context that produced it.
 */
interface InvocationOnlyValueProvider extends ValueProvider {}
