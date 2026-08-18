<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Reflector;

/** Executes one already-composed class/property/method attribute usage. */
interface AttributeHandlerInterface
{
    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void;
}
