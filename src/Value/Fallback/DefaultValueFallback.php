<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;

/** Native PHP parameter default. */
final readonly class DefaultValueFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return $target instanceof ParameterTarget && $target->hasDefault;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        return $target instanceof ParameterTarget && $target->hasDefault
            ? new ValueResult($target->default)
            : null;
    }
}
