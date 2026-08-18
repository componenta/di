<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueResult;

/** Supplies an attribute-owned fallback when no caller/trusted value exists. */
interface ValueDefaultHandlerInterface extends AttributeHandlerInterface
{
    public function defaultValue(
        object $attribute,
        ValueTargetInterface $target,
        ValueContext $context,
    ): ?ValueResult;
}
