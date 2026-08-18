<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

/** Immutable ordering definition for one fallback strategy. */
final readonly class ValueFallbackDefinition
{
    /** @var list<non-empty-string> */
    public array $before;

    /** @var list<non-empty-string> */
    public array $after;

    /**
     * @param non-empty-string $id
     * @param list<non-empty-string> $before
     * @param list<non-empty-string> $after
     */
    public function __construct(
        public string $id,
        public ValueFallbackInterface $fallback,
        array $before = [],
        array $after = [],
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Value fallback id must be non-empty.');
        }
        $this->before = array_values($before);
        $this->after = array_values($after);
    }
}
