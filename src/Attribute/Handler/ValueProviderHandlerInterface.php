<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;

/** Produces the raw value for a target carrying a ValueProvider capability. */
interface ValueProviderHandlerInterface extends AttributeHandlerInterface
{
    public ValueProviderPrecedence $precedence { get; }

    public function provide(
        object $attribute,
        ValueTargetInterface $target,
        ValueContext $context,
    ): mixed;
}
