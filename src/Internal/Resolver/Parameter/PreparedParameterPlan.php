<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/** Immutable prepared execution structure for one ordered parameter list. @internal */
final readonly class PreparedParameterPlan
{
    public bool $empty;

    /**
     * @param list<PreparedParameter> $parameters
     * @param list<ParameterTarget> $targets
     */
    public function __construct(
        public array $parameters,
        public array $targets,
        public int $resolverRevision,
        public object $owner,
    ) {
        $this->empty = $parameters === [];
    }
}
