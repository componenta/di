<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Rejects dependencies resolved with incomplete extension semantics during bootstrap.
 *
 * @internal
 */
final class BootstrapResolutionGuard
{
    private bool $active = true;

    /** @var list<array{target:ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty,phase:?AttributePhase,usages:list<array{AttributeDefinition,class-string,int}>}> */
    private array $attributeUses = [];

    /** @var list<array{target:ParameterTarget,prefix:list<ParameterResolverInterface>,winner:?ParameterResolverInterface}> */
    private array $parameterUses = [];

    /** @var list<ParameterResolverInterface>|null */
    private ?array $finalResolvers = null;

    public function __construct(private readonly AttributePlanBuilder $plans) {}

    public bool $isActive {
        get => $this->active;
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function recordAttributes(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        ?AttributePhase $phase = null,
        ?AttributePlan $plan = null,
    ): void {
        if (!$this->active || $target->getAttributes() === []) {
            return;
        }

        $this->attributeUses[] = [
            'target' => $target,
            'phase' => $phase,
            'usages' => CompletedAttributeGuard::semantics($plan ?? $this->plans->build($target), $phase),
        ];
    }

    /** @param list<ParameterResolverInterface> $prefix */
    public function recordParameter(ParameterTarget $target, array $prefix, bool $resolved = true): void
    {
        if ($this->active) {
            $winner = $resolved && $prefix !== [] ? $prefix[array_key_last($prefix)] : null;
            $needsProof = $this->finalResolvers === null;
            foreach ($this->finalResolvers ?? [] as $resolver) {
                if ($resolver === $winner) {
                    break;
                }
                if (!in_array($resolver, $prefix, true)) {
                    $needsProof = true;
                    break;
                }
            }
            if (!$needsProof) {
                // Resolution with the complete sealed prefix needs no bootstrap
                // proof. In-flight work may still return an older prefix.
                return;
            }
            $this->parameterUses[] = [
                'target' => $target,
                'prefix' => $prefix,
                'winner' => $winner,
            ];
        }
    }

    /** @param list<ParameterResolverInterface> $resolvers */
    public function finish(array $resolvers): void
    {
        $this->finalResolvers = $resolvers;
        try {
            do {
                $revision = $this->plans->revision;
                $parameterCount = count($this->parameterUses);
                foreach ($this->attributeUses as $use) {
                    $current = CompletedAttributeGuard::semantics($this->plans->build($use['target']), $use['phase']);
                    if ($current !== $use['usages']) {
                        throw new InvalidConfigurationException(sprintf(
                            'DI bootstrap used %s before its required attribute extensions were registered. Register these extensions before factories that resolve this dependency.',
                            self::targetName($use['target']),
                        ));
                    }
                }
                foreach ($this->parameterUses as $use) {
                    $winner = $use['winner'];
                    foreach ($resolvers as $resolver) {
                        if ($resolver === $winner) {
                            break;
                        }
                        if (!in_array($resolver, $use['prefix'], true) && $resolver->supports($use['target'])) {
                            throw new InvalidConfigurationException(sprintf(
                                'DI bootstrap resolved %s before applicable parameter resolver %s was registered ahead of %s. Register this resolver before factories that resolve this dependency.',
                                self::targetName($use['target']->reflection),
                                $resolver::class,
                                $winner === null ? 'native argument omission' : $winner::class,
                            ));
                        }
                    }
                }
                // Discovery and immutable classification can resolve new targets.
                // Recheck proof after metadata changes, never replay completed work.
                // The final poll can also resume work with an older resolver prefix.
                $nextRevision = $this->plans->revision;
            } while ($revision !== $nextRevision || $parameterCount !== count($this->parameterUses));
        } finally {
            $this->active = false;
            $this->finalResolvers = null;
            $this->attributeUses = [];
            $this->parameterUses = [];
        }
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
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
