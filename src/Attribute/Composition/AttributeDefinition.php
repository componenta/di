<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;

/** Immutable semantic definition of one DI attribute class. */
final readonly class AttributeDefinition
{
    /** @var list<class-string<AttributeCapabilityInterface>> */
    public array $capabilities;
    /** @var list<class-string> */
    public array $requires;
    /** @var list<class-string> */
    public array $forbids;
    /** @var list<class-string> */
    public array $before;
    /** @var list<class-string> */
    public array $after;
    /** @var list<AttributeCompositionRuleInterface> */
    public array $rules;

    /**
     * Selectors in requires/forbids/before/after may reference either another
     * attribute class or an AttributeCapabilityInterface class.
     *
     * A handler may support object targets, parameter targets, or both by
     * implementing the corresponding execution contract(s).
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
        public AttributeHandlerInterface|ParameterAttributeHandlerInterface|null $handler = null,
        array $capabilities = [],
        array $requires = [],
        array $forbids = [],
        array $before = [],
        array $after = [],
        array $rules = [],
        public AttributePhase $phase = AttributePhase::AfterInstantiation,
    ) {
        // Validation can autoload user code. Own every array slot before that
        // code can rebind a caller reference; rule objects retain their identity.
        $this->capabilities = self::snapshot($capabilities);
        $this->requires = self::snapshot($requires);
        $this->forbids = self::snapshot($forbids);
        $this->before = self::snapshot($before);
        $this->after = self::snapshot($after);
        $this->rules = self::snapshot($rules);

        if (!class_exists($attribute) && !interface_exists($attribute) && !class_exists($attribute, false)) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute definition target "%s" is not available.',
                $attribute,
            ));
        }

        foreach ($this->capabilities as $capability) {
            if (!is_a($capability, AttributeCapabilityInterface::class, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'Capability "%s" for attribute "%s" must implement %s.',
                    $capability,
                    $attribute,
                    AttributeCapabilityInterface::class,
                ));
            }
        }

        foreach ($this->rules as $rule) {
            if (!$rule instanceof AttributeCompositionRuleInterface) {
                throw new InvalidConfigurationException(sprintf(
                    'Composition rule for attribute "%s" must implement %s; got %s.',
                    $attribute,
                    AttributeCompositionRuleInterface::class,
                    get_debug_type($rule),
                ));
            }
        }

        self::assertSelectors($this->requires, 'requires');
        self::assertSelectors($this->forbids, 'forbids');
        self::assertSelectors($this->before, 'before');
        self::assertSelectors($this->after, 'after');
    }

    /**
     * @template T
     * @param list<T> $values
     * @return list<T>
     */
    private static function snapshot(array $values): array
    {
        if ($values === []) {
            return [];
        }
        return array_map(static fn(mixed $value): mixed => $value, $values);
    }

    /** @param list<class-string> $selectors */
    private static function assertSelectors(array $selectors, string $kind): void
    {
        foreach ($selectors as $selector) {
            if (!class_exists($selector) && !interface_exists($selector) && !class_exists($selector, false)) {
                throw new InvalidConfigurationException(sprintf(
                    'Attribute composition selector "%s" in %s is not available.',
                    $selector,
                    $kind,
                ));
            }
        }
    }
}
