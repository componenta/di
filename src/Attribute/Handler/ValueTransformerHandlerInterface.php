<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;

/** Transforms an already acquired raw value. */
interface ValueTransformerHandlerInterface extends AttributeHandlerInterface
{
    public function transform(
        object $attribute,
        mixed $value,
        ValueTargetInterface $target,
        ValueContext $context,
    ): mixed;
}
