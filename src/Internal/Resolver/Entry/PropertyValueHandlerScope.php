<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Reflector;

/** Defines the property operation used by ordinary and owner-authorized handlers. @internal */
abstract class PropertyValueHandlerScope
{
    /** @param (Closure(string $name,mixed &$value):bool)|null $readArgument */
    abstract protected function handleProperty(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
        ?Closure $readArgument,
    ): void;
}
