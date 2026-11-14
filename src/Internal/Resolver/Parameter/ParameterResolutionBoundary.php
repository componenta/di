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
     * @param class-string|null $diagnosticOwner
     * @return array<string|int,mixed>
     */
    public static function publicParameters(
        PreparedParameterPlan $plan,
        array $provided,
        ?string $diagnosticOwner = null,
    ): array {
        MappedRequestParameterSourceGuard::assertTargetsContextNoConflicts(
            $plan->targets,
            $provided,
            $diagnosticOwner,
        );

        return ResolutionMetadata::publicParameters($provided);
    }

    private function __construct() {}
}
