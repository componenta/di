<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Attribute\Handler\AttributeHandlerInterface;
use InvalidArgumentException;

/** Immutable semantic definition of one DI attribute class. */
final readonly class AttributeDefinition
{
    /**
     * Selectors in requires/forbids/before/after may reference either another
     * attribute class or an AttributeCapabilityInterface class.
     *
     * @param class-string $attribute
     * @param list<class-string<AttributeCapabilityInterface>> $capabilities
     * @param list<class-string> $requires
     * @param list<class-string> $forbids
     * @param list<class-string> $before
     * @param list<class-string> $after
     */
    public function __construct(
        public string $attribute,
        public AttributeHandlerInterface $handler,
        public array $capabilities = [],
        public array $requires = [],
        public array $forbids = [],
        public array $before = [],
        public array $after = [],
    ) {
        if (!class_exists($attribute) && !interface_exists($attribute)) {
            throw new InvalidArgumentException(sprintf(
                'Attribute definition target "%s" is not available.',
                $attribute,
            ));
        }

        foreach ($capabilities as $capability) {
            if (!is_a($capability, AttributeCapabilityInterface::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Capability "%s" for attribute "%s" must implement %s.',
                    $capability,
                    $attribute,
                    AttributeCapabilityInterface::class,
                ));
            }
        }

        self::assertSelectors($requires, 'requires');
        self::assertSelectors($forbids, 'forbids');
        self::assertSelectors($before, 'before');
        self::assertSelectors($after, 'after');
    }

    /** @param list<class-string> $selectors */
    private static function assertSelectors(array $selectors, string $kind): void
    {
        foreach ($selectors as $selector) {
            if (!class_exists($selector) && !interface_exists($selector)) {
                throw new InvalidArgumentException(sprintf(
                    'Attribute composition selector "%s" in %s is not available.',
                    $selector,
                    $kind,
                ));
            }
        }
    }
}
