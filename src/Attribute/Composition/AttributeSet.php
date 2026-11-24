<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Internal\ResolutionMetadata;

use function Componenta\DI\Internal\is_attribute_class_loaded;

/** Immutable unordered view used by custom composition rules. */
final readonly class AttributeSet
{
    /** @var list<AttributeUsage> */
    public array $attributes;

    /** @param list<AttributeUsage> $attributes */
    public function __construct(array $attributes)
    {
        $this->attributes = ResolutionMetadata::snapshot($attributes);
    }

    /**
     * Selector may be an attribute class/interface or a capability class.
     *
     * @param class-string $selector
     * @return list<AttributeUsage>
     */
    public function all(string $selector): array
    {
        $selectorWasLoaded = is_attribute_class_loaded($selector);
        $matches = [];
        foreach ($this->attributes as $usage) {
            if ($usage->matches($selector)) {
                $matches[] = $usage;
            }
        }
        return !$selectorWasLoaded && is_attribute_class_loaded($selector)
            ? $this->loadedMatches($selector)
            : $matches;
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
        $selectorWasLoaded = is_attribute_class_loaded($selector);
        foreach ($this->attributes as $usage) {
            if ($usage->matches($selector)) {
                return true;
            }
        }
        return !$selectorWasLoaded && is_attribute_class_loaded($selector) && $this->loadedMatches($selector) !== [];
    }

    /**
     * Recheck declarations skipped before the selector became available without
     * retrying autoloaders for any attribute still unavailable.
     *
     * @param class-string $selector
     * @return list<AttributeUsage>
     */
    private function loadedMatches(string $selector): array
    {
        $capability = is_a($selector, AttributeCapabilityInterface::class, true);
        $matches = [];
        foreach ($this->attributes as $usage) {
            if ($capability
                ? $usage->hasCapability($selector)
                : is_attribute_class_loaded($usage->attributeClass) && $usage->is($selector)
            ) {
                $matches[] = $usage;
            }
        }
        return $matches;
    }
}
