<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Rejects mapped HTTP values before an explicitly declared source can resolve. */
final readonly class MappedRequestSourceConflictResolver implements ParameterResolverInterface
{
    public const int PRIORITY = 1400;

    public function supports(ParameterTarget $target): bool
    {
        return MappedRequestParameterSourceGuard::supportsTarget($target);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        MappedRequestParameterSourceGuard::assertTargetContextNoConflicts(
            $target,
            $context->provided,
        );

        return null;
    }
}
