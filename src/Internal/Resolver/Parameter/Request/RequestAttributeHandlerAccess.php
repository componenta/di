<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Closure;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Passes request source checks to the active attribute execution. @internal */
abstract readonly class RequestAttributeHandlerAccess extends RequestAttributeHandlerScope
{
    /**
     * @param list<Closure():void>|null $sourceChecks
     * @param-out list<Closure():void> $sourceChecks
     */
    public static function resolve(
        RequestAttributeHandlerScope $handler,
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        ParameterAttributeValue $value,
        ?array &$sourceChecks,
    ): ParameterAttributeValue {
        return $handler->resolveRequest($attribute, $target, $context, $value, $sourceChecks);
    }
}
