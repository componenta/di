<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\PropertyTarget;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;

/** Allows transformer-only property declarations to transform an initialized value. */
final readonly class PropertyInitialValueFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return $target instanceof PropertyTarget;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        if (!$target instanceof PropertyTarget || $context->object === null) {
            return null;
        }

        $property = $target->reflection;

        return $property->isInitialized($context->object)
            ? new ValueResult($property->getValue($context->object))
            : null;
    }
}
