<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use InvalidArgumentException;

/** Immutable semantic definition of one DI attribute class. */
final readonly class AttributeDefinition
{
    /**
     * Selectors in requires/forbids/before/after may reference either another
     * attribute class or an AttributeCapabilityInterface class.
     *
     * A null handler is valid for parameter-only attributes: their composition
     * is described here, while execution belongs to ParameterResolverInterface.
     *
     * @param class-string $attribute
     * @param list<class-string<AttributeCapabilityInterface>> $capabilities
     * @param list<class-string> $requires
     * @param list<class-string> $forbids
     * @param list<class-string> $before
     * @param list<class-string> $after
     * @param list<AttributeCompositionRuleInterface> $rules
     */
    public function __construct(
        public string $attribute,
        public ?AttributeHandlerInterface $handler = null,
        public array $capabilities = [],
        public array $requires = [],
        public array $forbids = [],
        public array $before = [],
        public array $after = [],
        public array $rules = [],
        public int $version = 1,
        public AttributePhase $phase = AttributePhase::AfterInstantiation,
    ) {
        if (!class_exists($attribute) && !interface_exists($attribute)) {
            throw new InvalidArgumentException(sprintf(
                'Attribute definition target "%s" is not available.',
                $attribute,
            ));
        }
        if ($version < 1) {
            throw new InvalidArgumentException('Attribute definition version must be at least 1.');
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

        foreach ($rules as $rule) {
            if (!$rule instanceof AttributeCompositionRuleInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Composition rule for attribute "%s" must implement %s; got %s.',
                    $attribute,
                    AttributeCompositionRuleInterface::class,
                    get_debug_type($rule),
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
