<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/** Immutable prepared resolver selection for one parameter target. @internal */
final readonly class PreparedParameter
{
    /** @param list<int> $resolverSlots */
    public function __construct(
        public ParameterTarget $target,
        public array $resolverSlots,
    ) {}
}
