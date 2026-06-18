<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

use ReflectionParameter;
use ReflectionProperty;

/**
 * Optional extension for matchers that can persist immutable metadata needed
 * to execute a compiled plan without re-reading the target.
 */
interface CompilesPlanPayloadInterface extends AttributeMatcherInterface
{
    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed;
}
