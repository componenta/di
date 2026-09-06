<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Rule;

use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionParameter;
use ReflectionProperty;

/** Enforces the dual creation/value-source semantics of #[Proxy] injection points. */
final readonly class ProxyCompositionRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($attribute->target instanceof ReflectionParameter
            || $attribute->target instanceof ReflectionProperty
        ) {
            foreach ($set->all(ValueProvider::class) as $provider) {
                if ($provider->is(Make::class)) {
                    continue;
                }

                throw new AttributeCompositionException(sprintf(
                    '#[%s] cannot be combined with value provider #[%s] on the same target.',
                    $attribute->attributeClass,
                    $provider->attributeClass,
                ));
            }
        }
    }
}
