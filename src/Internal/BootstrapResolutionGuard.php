<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetAccess;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargets;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use WeakMap;
use WeakReference;

/**
 * Rejects dependencies resolved with incomplete extension semantics during bootstrap.
 *
 * @internal
 * @phpstan-type Selection array{classified:array<int,bool>,winner:?ParameterResolverInterface}
 * @phpstan-type ParameterUse array{target:ParameterTarget,selection:Selection,pending:list<string>}
 * @phpstan-type ReleasedUse array{
 *     target:ParameterTarget|WeakReference<Closure|ReflectionParameter|ParameterTarget>,
 *     position:int,
 *     selection:Selection,
 *     pending:list<string>,
 *     requiredAttributes:list<string>,
 *     declaredAttributes:list<class-string>,
 *     parameterName:string,
 *     declaringContext:string
 * }
 */
final class BootstrapResolutionGuard
{
    private bool $active = true;

    /** @var list<array{target:ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty,phase:?AttributePhase,usages:list<array{AttributeDefinition,class-string,int}>,declarations:list<class-string>,captured:list<ReflectionAttribute<object>>|null}> */
    private array $attributeUses = [];

    /** @var list<ParameterUse> */
    private array $parameterUses = [];
    private int $parameterChecks = 0;

    /** @var list<ParameterResolverInterface>|null */
    private ?array $finalResolvers = null;
    /** @var list<ParameterResolverInterface>|null */
    private ?array $sealedResolvers = null;

    /** @var list<array{attribute:class-string,phase:?AttributePhase,property:bool}> */
    private array $pendingDeclarations = [];
    /** @var list<ReleasedUse> */
    private array $releasedUses = [];
    /** @var WeakMap<ParameterTarget,WeakReference<Closure>>|null */
    private ?WeakMap $closureOwners = null;

    public function __construct(
        private readonly AttributePlans $plans,
        private readonly ParameterTargets $targets,
    ) {}

    public bool $isActive {
        get => $this->active;
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<ReflectionAttribute<object>>|null $captured
     */
    public function recordAttributes(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        ?AttributePhase $phase = null,
        ?AttributePlan $plan = null,
        ?array $captured = null,
    ): void {
        if (!$this->active) {
            return;
        }
        $declared = $captured ?? $target->getAttributes();
        if ($declared === [] && ($plan === null || $plan->usages === [])) {
            return;
        }

        $this->attributeUses[] = [
            'target' => $target,
            'phase' => $phase,
            'usages' => CompletedAttributeGuard::semantics($plan ?? $this->plans->build($target, $captured), $phase),
            'declarations' => array_values(array_map(static fn(ReflectionAttribute $attribute): string => $attribute->getName(), $declared)),
            'captured' => $captured,
        ];
    }

    /** @return list<class-string> */
    public static function unavailableDeclarations(ParameterTarget $target): array
    {
        return array_values(array_filter($target->attributeClasses, self::isUnavailable(...)));
    }

    /** @param list<ParameterTarget> $targets */
    public function recordClosureOwner(array $targets, Closure $closure): void
    {
        $owners = $this->closureOwners ??= new WeakMap();
        $owner = WeakReference::create($closure);
        foreach ($targets as $target) {
            if ($target->attributeClasses !== []) {
                $owners[$target] = $owner;
            }
        }
    }

    /**
     * @param list<ParameterResolverInterface> $original
     * @param list<int> $slots
     * @param list<string> $pending
     */
    public function recordParameter(
        ParameterTarget $target,
        array $original,
        array $slots,
        ?int $winnerSlot,
        array $pending,
    ): void {
        $winner = $winnerSlot === null ? null : $original[$winnerSlot];
        $applicable = array_fill_keys($slots, true);
        $classified = [];
        foreach ($original as $slot => $resolver) {
            if ($slot === $winnerSlot) {
                break;
            }
            $classified[spl_object_id($resolver)] = isset($applicable[$slot]);
        }
        $selection = ['classified' => $classified, 'winner' => $winner];
        $resolvers = $this->finalResolvers;
        if (!$this->active) {
            // The attempt started during bootstrap and resumed after publication.
            $this->assertParameterUse($target, $selection, $resolvers ?? [], $pending);
            return;
        }

        if ($resolvers !== null) {
            $needsCheck = self::declarationsChanged($pending);
            foreach ($resolvers as $resolver) {
                if ($resolver === $winner) {
                    break;
                }
                if (!isset($classified[spl_object_id($resolver)])) {
                    $needsCheck = true;
                    break;
                }
            }
            if ($needsCheck) {
                ++$this->parameterChecks;
            } elseif ($pending === []) {
                return;
            }
        }
        $this->parameterUses[] = ['target' => $target, 'selection' => $selection, 'pending' => $pending];
    }

    /** @param list<ParameterResolverInterface> $resolvers */
    public function recordSealedParameterChain(array $resolvers): void
    {
        $this->sealedResolvers = $resolvers;
    }

    /** @param Closure():void $releaseConfiguration */
    public function finish(Closure $releaseConfiguration): void
    {
        $resolvers = $this->sealedResolvers
            ?? throw new InvalidConfigurationException('Seal the parameter resolvers before publishing the container.');
        $this->sealedResolvers = null;
        $this->finalResolvers = $resolvers;
        /** @var WeakMap<ReflectionParameter,array<int,ParameterTarget>> $closureMethodTargets */
        $closureMethodTargets = new WeakMap();
        try {
            $this->verifyCompletedUses($resolvers, $closureMethodTargets);
        } finally {
            $this->active = false;
            try {
                $this->attributeUses = [];
            } finally {
                $this->parameterUses = [];
            }
        }

        $releaseConfiguration();
        $this->assertReleasedMetadataUnchanged($closureMethodTargets);
    }

    /**
     * Checks declarations that became available while proof-owned references were released.
     * @param WeakMap<ReflectionParameter,array<int,ParameterTarget>> $closureMethodTargets
     */
    private function assertReleasedMetadataUnchanged(WeakMap $closureMethodTargets): void
    {
        $pending = $this->pendingDeclarations;
        $parameterUses = $this->releasedUses;
        $this->pendingDeclarations = [];
        $this->releasedUses = [];
        do {
            $this->assertReleasedAttributesUnchanged($pending);
            $progress = false;
            foreach ($parameterUses as $index => $use) {
                if (!self::declarationsChanged($use['pending'])) {
                    continue;
                }
                $progress = true;
                $target = $this->restoreTarget($use['target'], $use['position']);
                if ($target !== null) {
                    $parameterUses[$index]['pending'] = $this->assertParameterUse($target, $use['selection'], $this->finalResolvers ?? [], $use['pending']);
                } elseif ($use['requiredAttributes'] !== []) {
                    self::assertLoadedAttributeMatches($use);
                    // A later live check can reveal another declaration in this sweep.
                    $parameterUses[$index]['pending'] = array_values(array_filter($use['pending'], self::isUnavailable(...)));
                } else {
                    $parameterUses[$index]['pending'] = [];
                }
                unset($target);
            }
            if (!$progress && count($closureMethodTargets) !== 0) {
                // A caller can retarget its reflector during configuration release.
                // Verify again after those original snapshots are no longer needed.
                foreach ($closureMethodTargets as $source => $snapshots) {
                    unset($closureMethodTargets[$source], $source, $snapshots);
                }
                $progress = true;
            }
        } while ($progress);
    }

    /** @param list<array{attribute:class-string,phase:?AttributePhase,property:bool}> $pending */
    private function assertReleasedAttributesUnchanged(array $pending): void
    {
        foreach ($pending as $use) {
            $definition = $this->plans->loadedDefinition($use['attribute']);
            if ($definition !== null
                && CompletedAttributeGuard::appliesToPhase($definition, $use['attribute'], $use['phase'], $use['property'])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'DI bootstrap loaded attribute "%s" on a used target while releasing its resolution proof.',
                    $use['attribute'],
                ));
            }
        }
    }

    /**
     * @param list<ParameterResolverInterface> $resolvers
     * @param WeakMap<ReflectionParameter,array<int,ParameterTarget>> $closureMethodTargets
     */
    private function verifyCompletedUses(array $resolvers, WeakMap $closureMethodTargets): void
    {
        do {
            $revision = $this->plans->refreshAvailability();
            $parameterChecks = $this->parameterChecks;
            foreach ($this->attributeUses as $use) {
                $current = CompletedAttributeGuard::semantics($this->plans->build($use['target'], $use['captured']), $use['phase']);
                if ($current !== $use['usages']) {
                    throw new InvalidConfigurationException(sprintf(
                        'DI bootstrap used %s before its required attribute extensions were registered. Register these extensions before factories that resolve this dependency.',
                        self::targetName($use['target']),
                    ));
                }
            }
            foreach ($this->parameterUses as $index => $use) {
                $this->parameterUses[$index]['pending'] = $this->assertParameterUse(
                    $use['target'],
                    $use['selection'],
                    $resolvers,
                    $use['pending'],
                );
            }
            $nextRevision = $this->plans->refreshAvailability();
        } while ($revision !== $nextRevision
            || $parameterChecks !== $this->parameterChecks
            || array_any($this->parameterUses, static fn(array $use): bool => self::declarationsChanged($use['pending'])));

        // The checkpoint holds only declaration names and diagnostics so that
        // the final scope can release closure reflectors before checking it.
        foreach ($this->attributeUses as $use) {
            foreach ($use['declarations'] as $attribute) {
                if (!class_exists($attribute, false) && !interface_exists($attribute, false)) {
                    $this->pendingDeclarations[] = [
                        'attribute' => $attribute,
                        'phase' => $use['phase'],
                        'property' => $use['target'] instanceof ReflectionProperty,
                    ];
                }
            }
        }
        foreach ($this->parameterUses as $use) {
            $pending = array_values(array_filter($use['pending'], self::isUnavailable(...)));
            if ($pending === []) {
                continue;
            }
            $reference = $this->detachTarget($use['target'], $closureMethodTargets);
            $requiredAttributes = $reference instanceof WeakReference
                ? self::unmatchedAttributeNames($use['selection'], $resolvers)
                : [];
            $this->releasedUses[] = [
                'target' => $reference,
                'position' => $use['target']->position,
                'selection' => $use['selection'],
                'pending' => $pending,
                'requiredAttributes' => $requiredAttributes,
                'declaredAttributes' => $requiredAttributes === [] ? [] : $use['target']->attributeClasses,
                'parameterName' => $use['target']->name,
                'declaringContext' => $use['target']->declaringContext,
            ];
        }
    }

    /**
     * @param Selection $selection
     * @param list<ParameterResolverInterface> $resolvers
     * @return list<string>
     */
    private static function unmatchedAttributeNames(array $selection, array $resolvers): array
    {
        $required = [];
        foreach ($resolvers as $resolver) {
            if ($resolver === $selection['winner']) {
                break;
            }
            if ($resolver instanceof AttributeMatchingParameterResolver && !($selection['classified'][spl_object_id($resolver)] ?? false)) {
                $required[] = $resolver->attribute;
            }
        }
        return $required;
    }

    /** @param ReleasedUse $use */
    private static function assertLoadedAttributeMatches(array $use): void
    {
        foreach ($use['requiredAttributes'] as $required) {
            $loaded = is_attribute_class_loaded($required);
            foreach ($use['declaredAttributes'] as $declared) {
                if ($declared === $required
                    || ($loaded && is_attribute_class_loaded($declared) && is_a($declared, $required, true))
                ) {
                    throw new InvalidConfigurationException(sprintf(
                        'DI bootstrap resolved $%s of %s before a parameter resolver matching "%s" could run.',
                        $use['parameterName'],
                        $use['declaringContext'],
                        $required,
                    ));
                }
            }
        }
    }

    /**
     * Rechecks only classification and returns any names that are still unavailable.
     *
     * @param Selection $selection
     * @param list<ParameterResolverInterface> $resolvers
     * @param list<string> $pending
     * @return list<string>
     */
    private function assertParameterUse(ParameterTarget $target, array $selection, array $resolvers, array $pending): array
    {
        if ($target->attributeClasses === []) {
            self::assertParameterSelection($target, $selection, $resolvers, declarationsChanged: false);
            return [];
        }

        $changed = false;
        do {
            $missing = array_values(array_filter($pending, self::isUnavailable(...)));
            $changed = $changed || $missing !== $pending;
            [, $observed] = $this->targets->observeAttributeQueries(
                $target,
                static fn(ParameterTarget $observedTarget) => self::assertParameterSelection(
                    $observedTarget,
                    $selection,
                    $resolvers,
                    declarationsChanged: $changed,
                ),
            );
            $pending = $observed === [] ? $missing : array_values(array_unique([...$missing, ...$observed], SORT_STRING));
        } while (self::declarationsChanged($pending));

        return $pending;
    }

    /**
     * @param Selection $selection
     * @param list<ParameterResolverInterface> $resolvers
     */
    private static function assertParameterSelection(
        ParameterTarget $target,
        array $selection,
        array $resolvers,
        bool $declarationsChanged,
    ): void {
        $winner = $selection['winner'];
        foreach ($resolvers as $resolver) {
            if ($resolver === $winner) {
                if ($declarationsChanged && !$resolver->supports($target)) {
                    throw new InvalidConfigurationException(sprintf(
                        'DI bootstrap selected parameter resolver %s for %s before its final attribute metadata was available.',
                        $resolver::class,
                        self::targetName($target->reflection),
                    ));
                }
                return;
            }
            $applicable = $selection['classified'][spl_object_id($resolver)] ?? null;
            if (($applicable === null || (!$applicable && $declarationsChanged)) && $resolver->supports($target)) {
                throw new InvalidConfigurationException(sprintf(
                    'DI bootstrap resolved %s before applicable parameter resolver %s could run ahead of %s. Register the resolver and load its attributes before factories that resolve this dependency.',
                    self::targetName($target->reflection),
                    $resolver::class,
                    $winner === null ? 'native argument omission' : $winner::class,
                ));
            }
        }
    }

    /** @param list<string> $declarations */
    private static function declarationsChanged(array $declarations): bool
    {
        return array_any($declarations, static fn(string $name): bool => !self::isUnavailable($name));
    }

    private static function isUnavailable(string $name): bool
    {
        return !is_attribute_class_loaded($name);
    }

    /**
     * @param WeakMap<ReflectionParameter,array<int,ParameterTarget>> $closureMethodTargets
     * @return ParameterTarget|WeakReference<Closure|ReflectionParameter|ParameterTarget>
     */
    private function detachTarget(ParameterTarget $target, WeakMap $closureMethodTargets): ParameterTarget|WeakReference
    {
        $owner = $this->closureOwners[$target] ?? null;
        if ($owner !== null) {
            return $owner;
        }
        $declaration = ParameterTargetAccess::declaration($target);
        $reflection = $declaration->metadataTarget();
        if ($reflection::class !== ReflectionParameter::class) {
            return WeakReference::create($reflection);
        }
        $function = $reflection->getDeclaringFunction();
        if ($function instanceof ReflectionFunction && $function->isClosure()) {
            return WeakReference::create($function->getClosure());
        }

        if (is_closure_reflector($function)) {
            // Native Closure::__invoke reflectors can own the closure's captures.
            // Keep the original snapshot only while the caller's reflector lives.
            $source = $declaration->closureMethodSource();
            if ($source !== null) {
                $closureMethodTargets[$source] ??= [];
                $closureMethodTargets[$source][spl_object_id($target)] = $target;
            }
            return WeakReference::create($target);
        }

        return $target;
    }

    /** @param ParameterTarget|WeakReference<Closure|ReflectionParameter|ParameterTarget> $reference */
    private function restoreTarget(ParameterTarget|WeakReference $reference, int $position): ?ParameterTarget
    {
        if ($reference instanceof ParameterTarget) {
            return $reference;
        }
        return match (true) {
            ($target = $reference->get()) instanceof Closure => $this->targets->create(new ReflectionParameter($target, $position)),
            $target instanceof ParameterTarget => $target,
            $target instanceof ReflectionParameter => $this->targets->create($target),
            default => null,
        };
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetName(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): string {
        if ($target instanceof ReflectionClass) {
            return $target->getName();
        }
        if ($target instanceof ReflectionParameter) {
            return sprintf(
                '$%s of %s%s()',
                $target->getName(),
                ($target->getDeclaringClass()?->getName() ?? '') . '::',
                $target->getDeclaringFunction()->getName(),
            );
        }
        return $target->getDeclaringClass()->getName() . '::' . $target->getName();
    }
}
