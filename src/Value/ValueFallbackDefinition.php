<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

use InvalidArgumentException;

/** Declarative ordering metadata for one implicit value fallback. */
final readonly class ValueFallbackDefinition
{
    /**
     * @param non-empty-string $id
     * @param list<non-empty-string> $before
     * @param list<non-empty-string> $after
     */
    public function __construct(
        public string $id,
        public ValueFallbackInterface $fallback,
        public array $before = [],
        public array $after = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Value fallback id must be non-empty.');
        }

        foreach ([...$before, ...$after] as $dependency) {
            if ($dependency === '') {
                throw new InvalidArgumentException('Value fallback ordering ids must be non-empty.');
            }
        }
    }
}
