<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

/**
 * Lazily supplies compiled DI plans.
 *
 * @phpstan-type DiPlans array{
 *     param?: array<class-string, array<string, array<int, string|array{kind: string, payload: mixed}>>>,
 *     prop?: array<class-string, array<string, string|array{kind: string, payload: mixed}>>
 * }
 */
interface PlanProviderInterface
{
    /**
     * @return array{param?: array, prop?: array}
     */
    public function plans(): array;
}
