<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;

/** Named values originating from an HTTP DTO mapping operation. */
final readonly class MappedValueFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return true;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        return array_key_exists($target->name, $context->resolution->mapped)
            ? new ValueResult($context->resolution->mapped[$target->name])
            : null;
    }
}
