<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

use Componenta\DI\ResolutionContext;

/** Per-value resolution state shared by providers, fallbacks and transformers. */
final readonly class ValueContext
{
    /** @var array<int, mixed> */
    public array $resolvedParameters;

    /** @param array<int, mixed> $resolvedParameters */
    public function __construct(
        public ResolutionContext $resolution,
        array $resolvedParameters = [],
        public ?object $object = null,
    ) {
        $this->resolvedParameters = $resolvedParameters;
    }
}
