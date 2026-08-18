<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

use Componenta\DI\Resolver\Target\ValueTargetInterface;

/** Implicit value source used only when no provider attribute supplies a value. */
interface ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool;

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult;
}
