<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;

/** Immutable unordered view used by custom composition rules. */
final readonly class AttributeSet
{
    /** @param list<AttributeUsage> $attributes */
    public function __construct(public array $attributes) {}

    /**
     * Selector may be an attribute class/interface or a capability class.
     *
     * @param class-string $selector
     * @return list<AttributeUsage>
     */
    public function all(string $selector): array
    {
        $matches = [];
        foreach ($this->attributes as $usage) {
            if ($this->matches($selector, $usage)) {
                $matches[] = $usage;
            }
        }
        return $matches;
    }

    /** @param class-string $selector */
    public function one(string $selector): ?AttributeUsage
    {
        $matches = $this->all($selector);
        if (count($matches) > 1) {
            throw new AttributeCompositionException(sprintf(
                'Selector "%s" is not singular in this attribute set.',
                $selector,
            ));
        }
        return $matches[0] ?? null;
    }

    /** @param class-string $selector */
    public function has(string $selector): bool
    {
        foreach ($this->attributes as $usage) {
            if ($this->matches($selector, $usage)) {
                return true;
            }
        }
        return false;
    }

    /** @param class-string $selector */
    private function matches(string $selector, AttributeUsage $usage): bool
    {
        if (is_a($selector, AttributeCapabilityInterface::class, true)) {
            foreach ($usage->definition->capabilities as $capability) {
                if (is_a($capability, $selector, true)) {
                    return true;
                }
            }
            return false;
        }

        return is_a($usage->attribute::class, $selector, true);
    }
}
