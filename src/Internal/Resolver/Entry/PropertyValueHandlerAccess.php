<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use ReflectionProperty;

/** Dispatches a selected built-in handler with the attempt's literal argument lookup. @internal */
abstract class PropertyValueHandlerAccess extends PropertyValueHandlerScope
{
    public static function handle(
        PropertyValueHandlerScope $handler,
        object $attribute,
        ReflectionProperty $target,
        ObjectCreationAttempt $attempt,
    ): void {
        $handler->handleProperty($attribute, $target, $attempt->context, $attempt->readParameter(...));
    }
}
