<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use ReflectionParameter;

/** Resolves caller-provided values and PHP defaults without DI autowiring. */
final readonly class ExplicitParametersResolver
{
    private ParametersResolver $resolver;

    public function __construct()
    {
        $this->resolver = new ParametersResolver(
            new ArrayResolver(),
            new ArrayTypedResolver(),
            new DefaultValueResolver(),
            new NullableResolver(),
        );
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int, mixed> $provided
     * @return array<int, mixed>
     */
    public function resolve(array $parameters, array $provided = []): array
    {
        return $this->resolver->resolve($parameters, $provided);
    }

    /**
     * Resolves a base argument set with caller overrides taking precedence by
     * parameter name, position or declared object type.
     *
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int, mixed> $base
     * @param array<string|int, mixed> $overrides
     * @return array<int, mixed>
     */
    public function resolveWithOverrides(
        array $parameters,
        array $base,
        array $overrides,
    ): array {
        if ($base === []) {
            return $this->resolve($parameters, $overrides);
        }

        if ($overrides === []) {
            return $this->resolve($parameters, $base);
        }

        $targets = $this->resolver->targets($parameters);
        $provided = $base;

        foreach ($targets as $target) {
            if (array_key_exists($target->name, $overrides)) {
                $provided[$target->name] = $overrides[$target->name];
                continue;
            }

            if (array_key_exists($target->position, $overrides)) {
                $provided[$target->name] = $overrides[$target->position];
                continue;
            }

            foreach ($target->typeNames as $typeName) {
                if (!array_key_exists($typeName, $overrides)) {
                    continue;
                }

                $value = $overrides[$typeName];
                if (is_object($value) && $target->accepts($value)) {
                    $provided[$target->name] = $value;
                    break;
                }
            }
        }

        return $this->resolver->resolveTargets($targets, $provided);
    }
}
