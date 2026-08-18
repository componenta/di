<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;

/** Wraps the normal value-resolution operation for one target. */
interface ValueWrapperHandlerInterface extends AttributeHandlerInterface
{
    /** @param Closure(): mixed $next */
    public function wrap(
        object $attribute,
        ValueTargetInterface $target,
        AttributePlan $plan,
        ValueContext $context,
        Closure $next,
    ): mixed;
}
