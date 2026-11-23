<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Closure;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Keeps mapped source checks on the execution that owns the attribute instance. @internal */
abstract readonly class RequestAttributeHandlerScope
{
    /**
     * @param list<Closure():void>|null $sourceChecks
     * @param-out list<Closure():void> $sourceChecks
     */
    abstract protected function resolveRequest(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        ParameterAttributeValue $value,
        ?array &$sourceChecks,
    ): ParameterAttributeValue;
}
