<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\Rule\ProxyCompositionRule;
use Componenta\DI\Exception\InvalidConfigurationException;
use ReflectionClass;
use ReflectionFunction;

/** Mutable composition-time registry, sealed before the container becomes usable. */
final class AttributeDefinitionRegistry
{
    private const int MINIMUM_SNAPSHOT_SIZE = 64;
    private const int DECLARATIONS_PER_PENDING_NAME = 4;

    /** @var array<class-string, AttributeDefinition> */
    private array $definitions = [];

    /** @var array<class-string<AttributeCapabilityInterface>, CapabilityPolicy> */
    private array $policies = [];

    /** @var array<class-string,true> */
    private array $unavailableAttributes = [];

    private bool $sealed = false;
    private int $generation = 0;
    private int $discoveryMisses = 0;
    private int $snapshotMinimum = self::MINIMUM_SNAPSHOT_SIZE;
    /** @var array{int, list<string>, list<string>}|null */
    private ?array $checkedDeclarations = null;
    /** @internal */
    public private(set) bool $targetedDiscovery = false;
    /** @internal An undiscovered declaration may introduce a user rule. */
    public private(set) bool $hasCustomRules = false;

    public function __construct()
    {
        foreach (self::corePolicies() as $policy) {
            $this->policies[$policy->capability] = $policy;
        }
    }

    /** @internal */
    public static function withTargetedDiscovery(): self
    {
        $registry = new self();
        $registry->targetedDiscovery = true;
        return $registry;
    }

    /** Also changes when a previously unavailable attribute class becomes loadable. */
    public int $revision {
        get {
            if ($this->unavailableAttributes === []) {
                return $this->generation;
            }
            if ($this->targetedDiscovery) {
                return $this->refreshLoaded();
            }
            $checked = [];
            $pending = $this->unavailableAttributes;
            while ($pending !== []) {
                $misses = $this->discoveryMisses;
                foreach ($pending as $attribute => $_) {
                    if (class_exists($attribute) || interface_exists($attribute) || class_exists($attribute, false)) {
                        // The fallback interface probe can autoload a class.
                        unset($this->unavailableAttributes[$attribute]);
                        ++$this->generation;
                    }
                }
                // Reentrant discovery can append names after the foreach snapshot.
                // Probe each newly tracked name once, without retrying old misses.
                if ($misses === $this->discoveryMisses) {
                    break;
                }
                $checked += $pending;
                $pending = array_diff_key($this->unavailableAttributes, $checked);
            }
            return $this->refreshLoaded();
        }
    }

    /** @internal Observes completed declarations without running autoloaders. */
    public function refreshLoaded(): int
    {
        if ($this->unavailableAttributes === []) {
            $this->checkedDeclarations = null;
            return $this->generation;
        }

        $pending = count($this->unavailableAttributes);
        $declarations = $pending >= $this->snapshotMinimum ? $this->declarationSnapshot($pending) : null;
        if ($declarations !== null && $declarations === $this->checkedDeclarations) {
            return $this->generation;
        }

        foreach ($this->unavailableAttributes as $attribute => $_) {
            // A later loader can declare a name already checked in a poll.
            if (class_exists($attribute, false) || interface_exists($attribute, false)) {
                unset($this->unavailableAttributes[$attribute]);
                ++$this->generation;
            }
        }
        $this->checkedDeclarations = $this->unavailableAttributes === [] ? null : $declarations;
        return $this->generation;
    }

    /** @return array{int, list<string>, list<string>}|null */
    private function declarationSnapshot(int $pending): ?array
    {
        // PHP lists linked classes, interfaces and their aliases without autoload.
        // A user replacement for a disabled builtin cannot supply that guarantee.
        if ($this->checkedDeclarations === null
            && (!\function_exists('get_declared_classes')
                || !\function_exists('get_declared_interfaces')
                || !new ReflectionFunction('get_declared_classes')->isInternal()
                || !new ReflectionFunction('get_declared_interfaces')->isInternal())
        ) {
            $this->snapshotMinimum = PHP_INT_MAX;
            return null;
        }

        $misses = $this->discoveryMisses;
        $classes = \get_declared_classes();
        $interfaces = \get_declared_interfaces();
        $minimum = intdiv(
            count($classes) + count($interfaces) + self::DECLARATIONS_PER_PENDING_NAME - 1,
            self::DECLARATIONS_PER_PENDING_NAME,
        );
        if ($pending < $minimum) {
            // Prefer individual probes if enumerating the symbol table is too expensive.
            $this->snapshotMinimum = max($this->snapshotMinimum, $minimum);
            return null;
        }
        return [$misses, $classes, $interfaces];
    }

    /** @internal */
    public function awaitsDiscovery(string $attribute): bool
    {
        return ($this->targetedDiscovery || $this->hasCustomRules) && isset($this->unavailableAttributes[$attribute]);
    }

    /** @param class-string $attribute */
    private function markUnavailable(string $attribute): void
    {
        if (!isset($this->unavailableAttributes[$attribute])) {
            $this->unavailableAttributes[$attribute] = true;
            ++$this->discoveryMisses;
            $offset = str_starts_with($attribute, '\\') ? 1 : 0;
            if (($attribute[$offset] ?? "\0") === "\0") {
                // PHP omits empty and NUL-prefixed alias keys from its declaration lists.
                $this->snapshotMinimum = PHP_INT_MAX;
                $this->checkedDeclarations = null;
            }
        }
    }

    public function register(AttributeDefinition $definition): void
    {
        $this->assertMutable();
        $attribute = new ReflectionClass($definition->attribute)->getName();
        if (isset($this->definitions[$attribute])) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute "%s" already has a semantic definition.',
                $definition->attribute,
            ));
        }

        foreach ($definition->capabilities as $capability) {
            if (!is_a($capability, AttributeCapabilityInterface::class, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'Attribute capability "%s" must implement %s.',
                    $capability,
                    AttributeCapabilityInterface::class,
                ));
            }
            $capability = new ReflectionClass($capability)->getName();
            $this->policies[$capability] ??= new CapabilityPolicy($capability);
        }

        $this->definitions[$attribute] = $definition;
        foreach ($definition->rules as $rule) {
            if (!$rule instanceof ProxyCompositionRule) {
                $this->hasCustomRules = true;
                break;
            }
        }
        ++$this->generation;
    }

    public function defineCapability(CapabilityPolicy $policy): void
    {
        $this->assertMutable();
        $capability = new ReflectionClass($policy->capability)->getName();
        $existing = $this->policies[$capability] ?? null;
        if ($existing !== null && $existing->maxPerTarget !== $policy->maxPerTarget) {
            throw new InvalidConfigurationException(sprintf(
                'Capability "%s" already has a different composition policy.',
                $policy->capability,
            ));
        }
        if ($existing === null) {
            $this->policies[$capability] = $policy;
            ++$this->generation;
        }
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    /** @param class-string $attributeClass */
    public function definition(string $attributeClass): ?AttributeDefinition
    {
        if (isset($this->definitions[$attributeClass])) {
            return $this->definitions[$attributeClass];
        }

        $available = class_exists($attributeClass, false) || interface_exists($attributeClass, false);
        if ($this->targetedDiscovery && !$available) {
            if (!class_exists($attributeClass) && !interface_exists($attributeClass) && !class_exists($attributeClass, false)) {
                $this->markUnavailable($attributeClass);
                return null;
            }
            $available = true;
            if (isset($this->unavailableAttributes[$attributeClass])) {
                unset($this->unavailableAttributes[$attributeClass]);
                ++$this->generation;
                if ($this->unavailableAttributes === []) {
                    $this->checkedDeclarations = null;
                }
            }
        }
        /** @var array<class-string, AttributeDefinition> $matches */
        $matches = [];
        foreach ($this->definitions as $registered => $definition) {
            if (is_a($attributeClass, $registered, true)) {
                $matches[$registered] = $definition;
            }
        }

        // A later is_a() probe may load the class after an earlier matching
        // definition was skipped. Select again with its complete ancestry.
        // This transition happens once; unavailable classes do not retry.
        if (!$available && (class_exists($attributeClass, false) || interface_exists($attributeClass, false))) {
            return $this->definition($attributeClass);
        }

        if ($matches === []) {
            if (!class_exists($attributeClass, false) && !interface_exists($attributeClass, false)) {
                $this->markUnavailable($attributeClass);
            }

            return null;
        }
        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        foreach (array_keys($matches) as $candidate) {
            foreach (array_keys($matches) as $other) {
                if ($candidate === $other) {
                    continue;
                }
                if (is_a($other, $candidate, true)) {
                    unset($matches[$candidate]);
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        throw new InvalidConfigurationException(sprintf(
            'Attribute "%s" matches multiple equally specific semantic definitions: %s.',
            $attributeClass,
            implode(', ', array_keys($matches)),
        ));
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function policy(string $capability): CapabilityPolicy
    {
        $capability = new ReflectionClass($capability)->getName();
        return $this->policies[$capability] ?? new CapabilityPolicy($capability);
    }

    /** @return list<AttributeDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<CapabilityPolicy> */
    public function policies(): array
    {
        return array_values($this->policies);
    }

    /** @return list<CapabilityPolicy> */
    private static function corePolicies(): array
    {
        return [
            new CapabilityPolicy(ValueProvider::class, 1),
            new CapabilityPolicy(ValueTransformer::class),
            new CapabilityPolicy(CreationStrategy::class, 1),
            new CapabilityPolicy(ConstructorPolicy::class, 1),
            new CapabilityPolicy(LifecycleHook::class),
        ];
    }

    private function assertMutable(): void
    {
        if ($this->sealed) {
            throw new InvalidConfigurationException('Attribute definition registry is sealed.');
        }
    }
}
