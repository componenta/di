<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;

/**
 * Checks mapped sources again before extensions consume invocation values.
 *
 * @internal
 */
final class ParameterResolutionBoundary
{
    /**
     * @param class-string|null $diagnosticOwner
     * @param (Closure():void)|null $beforeInvocation
     * @return (Closure():void)|null
     */
    public static function beforeConsumption(
        PreparedParameterPlan $plan,
        MappedRequestContext $provenance,
        ?string $diagnosticOwner,
        ?Closure $beforeInvocation,
    ): ?Closure {
        $targets = MappedRequestParameterSourceGuard::potentialSourceTargets($plan->targets, $provenance);
        if ($targets === []) {
            return $beforeInvocation;
        }

        return static function () use ($targets, $provenance, $diagnosticOwner, $beforeInvocation): void {
            $beforeInvocation?->__invoke();
            MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts(
                $targets,
                $provenance,
                $diagnosticOwner,
                autoload: false,
            );
        };
    }

    private function __construct() {}
}
