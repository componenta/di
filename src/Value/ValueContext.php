<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

use Componenta\DI\ResolutionContext;

/** Immutable context visible to one value-provider/transform operation. */
final readonly class ValueContext
{
    /** @param array<int, mixed> $resolvedParameters */
    public function __construct(
        public ResolutionContext $resolution,
        public array $resolvedParameters = [],
        public ?object $object = null,
    ) {}
}
