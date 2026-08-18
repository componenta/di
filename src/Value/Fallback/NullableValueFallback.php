<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;

/** Final nullable fallback. */
final readonly class NullableValueFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return $target->allowsNull;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        return $target->allowsNull ? new ValueResult(null) : null;
    }
}
