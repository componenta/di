<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

/** Immutable dispatch plan compiled once for one entry class. */
final readonly class AttributeExecutionPlan
{
    /**
     * @param list<AttributeInvocation> $before
     * @param list<AttributeInvocation> $after
     */
    public function __construct(
        public array $before = [],
        public array $after = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->before === [] && $this->after === [];
    }

    /** @return list<AttributeInvocation> */
    public function forPhase(AttributePhase $phase): array
    {
        return $phase === AttributePhase::BeforeInstantiation
            ? $this->before
            : $this->after;
    }
}
