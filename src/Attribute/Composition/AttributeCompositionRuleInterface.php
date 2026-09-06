<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

/** Open extension point for composition invariants beyond cardinality/selectors. */
interface AttributeCompositionRuleInterface
{
    /**
     * Validate one usage against the complete unordered attribute set.
     * Implementations should throw AttributeCompositionException on violation.
     */
    public function validate(AttributeUsage $attribute, AttributeSet $set): void;
}
