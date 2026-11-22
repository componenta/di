<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Fiber;
use WeakMap;

use function Componenta\DI\Internal\is_attribute_class_loaded;

/**
 * Owns the nested availability observations made during parameter classification.
 *
 * @internal
 */
final class ParameterAttributeObservation
{
    /** @var WeakMap<ParameterTarget,array<int,list<array<array-key,string>>>>|null */
    private ?WeakMap $queries = null;

    /**
     * @template T
     * @param Closure(ParameterTarget):T $classify
     * @return array{T,list<string>}
     */
    public function capture(ParameterTarget $target, Closure $classify): array
    {
        $target = $target->forObservation($this);
        $queries = $this->queries ??= new WeakMap();
        $execution = self::executionId();
        $queries[$target] ??= [];
        $scopes = &$queries[$target];
        $scopes[$execution][] = [];
        try {
            $result = $classify($target);
        } finally {
            $recorded = array_pop($scopes[$execution]);
            if ($scopes[$execution] === []) {
                unset($scopes[$execution]);
            }
            if ($scopes === []) {
                unset($queries[$target]);
            }
        }

        return [$result, array_values($recorded ?? [])];
    }

    public function record(ParameterTarget $target, string $attributeClass): void
    {
        $queries = $this->queries;
        if ($queries === null || !isset($queries[$target]) || is_attribute_class_loaded($attributeClass)) {
            return;
        }
        $execution = self::executionId();
        $scopes = &$queries[$target];
        $scope = array_key_last($scopes[$execution] ?? []);
        if ($scope !== null) {
            $scopes[$execution][$scope][$attributeClass] = $attributeClass;
        }
    }

    private static function executionId(): int
    {
        // An observation must not retain an abandoned Fiber.
        $fiber = Fiber::getCurrent();
        return $fiber === null ? 0 : spl_object_id($fiber);
    }
}
