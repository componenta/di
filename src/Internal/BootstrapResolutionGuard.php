<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\InvalidConfigurationException;
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

    /** @var list<array{target:ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty,usages:list<array{AttributeDefinition,class-string,int}>}> */
    private array $attributeUses = [];

    /** @var list<array{target:ParameterTarget,prefix:non-empty-list<ParameterResolverInterface>}> */
    private array $parameterUses = [];

    public function __construct(private readonly AttributePlanBuilder $plans) {}

    public bool $isActive {
        get => $this->active;
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function recordAttributes(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): void {
        if (!$this->active || $target->getAttributes() === []) {
            return;
        }

        $this->attributeUses[] = [
            'target' => $target,
            'usages' => self::semantics($this->plans->build($target)->usages),
        ];
    }

    /** @param non-empty-list<ParameterResolverInterface> $prefix */
    public function recordParameter(ParameterTarget $target, array $prefix): void
    {
        if ($this->active) {
            $this->parameterUses[] = ['target' => $target, 'prefix' => $prefix];
        }
    }

    /** @param list<ParameterResolverInterface> $resolvers */
    public function finish(array $resolvers): void
    {
        $this->active = false;
        try {
            foreach ($this->attributeUses as $use) {
                $current = self::semantics($this->plans->build($use['target'])->usages);
                if ($current !== $use['usages']) {
                    throw new InvalidConfigurationException(sprintf(
                        'DI bootstrap used %s before its required attribute extensions were registered. Register these extensions before factories that resolve this dependency.',
                        self::targetName($use['target']),
                    ));
                }
            }
            foreach ($this->parameterUses as $use) {
                $winner = $use['prefix'][array_key_last($use['prefix'])];
                foreach ($resolvers as $resolver) {
                    if ($resolver === $winner) {
                        break;
                    }
                    if (!in_array($resolver, $use['prefix'], true) && $resolver->supports($use['target'])) {
                        throw new InvalidConfigurationException(sprintf(
                            'DI bootstrap resolved %s before applicable parameter resolver %s was registered ahead of %s. Register this resolver before factories that resolve this dependency.',
                            self::targetName($use['target']->reflection),
                            $resolver::class,
                            $winner::class,
                        ));
                    }
                }
            }
        } finally {
            $this->attributeUses = [];
            $this->parameterUses = [];
        }
    }

    /**
     * @param list<AttributeUsage> $usages
     * @return list<array{AttributeDefinition,class-string,int}>
     */
    private static function semantics(array $usages): array
    {
        return array_map(
            static fn(AttributeUsage $usage): array => [
                $usage->definition,
                $usage->attributeClass,
                $usage->declarationOrder,
            ],
            $usages,
        );
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
