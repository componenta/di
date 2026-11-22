<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Immutable prepared execution structure for one ordered parameter list.
 *
 * @internal
 */
final readonly class PreparedParameterPlan
{
    /**
     * @param list<ParameterTarget> $targets
     */
    public function __construct(
        public array $targets,
        public int $resolverRevision,
        private object $owner,
        public bool $requiresFreshValidation,
    ) {}

    public function belongsTo(object $owner): bool
    {
        return $this->owner === $owner;
    }
}
