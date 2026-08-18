<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;

/** Trusted caller-provided values by name, position or declared class/interface key. */
final readonly class ExplicitValueFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return true;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        $values = $context->resolution->explicit;

        if (array_key_exists($target->name, $values)) {
            return new ValueResult($values[$target->name]);
        }

        if ($target instanceof ParameterTarget && array_key_exists($target->position, $values)) {
            return new ValueResult($values[$target->position]);
        }

        foreach ($target->typeNames as $typeName) {
            if (array_key_exists($typeName, $values)) {
                return new ValueResult($values[$typeName]);
            }
        }

        return null;
    }
}
