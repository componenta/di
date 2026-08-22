<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Rule;

use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
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
                if ($provider->attribute instanceof Make) {
                    continue;
                }

                throw new AttributeCompositionException(sprintf(
                    '#[%s] cannot be combined with value provider #[%s] on the same target.',
                    $attribute->attribute::class,
                    $provider->attribute::class,
                ));
            }
        }

        if ($attribute->target instanceof ReflectionProperty
            && !$attribute->target->isPromoted()
            && $attribute->target->isReadOnly()
            && $set->has(ValueTransformer::class)
        ) {
            throw new AttributeCompositionException(sprintf(
                '#[%s] cannot be combined with a value transformer on readonly property %s::$%s.',
                $attribute->attribute::class,
                $attribute->target->getDeclaringClass()->getName(),
                $attribute->target->getName(),
            ));
        }
    }
}
