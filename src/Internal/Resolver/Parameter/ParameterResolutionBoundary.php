<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;

/** Applies internal resolution metadata policy before public parameter execution. @internal */
final class ParameterResolutionBoundary
{
    /**
     * @param array<string|int,mixed> $provided
     * @return array<string|int,mixed>
     */
    public static function publicParameters(
        PreparedParameterPlan $plan,
        array $provided,
    ): array {
        MappedRequestParameterSourceGuard::assertTargetsContextNoConflicts(
            $plan->targets,
            $provided,
        );

        return ResolutionMetadata::publicParameters($provided);
    }

    private function __construct() {}
}
