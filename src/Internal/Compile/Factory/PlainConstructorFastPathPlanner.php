<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Compile\Factory;

use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Finds the narrow constructor shape that can be emitted without duplicating
 * the general parameter-resolution pipeline.
 *
 * The fast path is intentionally conservative: required parameters must be
 * plain single-class autowires and every remaining parameter must be a native
 * trailing default. Any attribute, request source, custom resolver, nullable
 * autowire, unsupported parameter shape, or other semantic extension keeps the
 * normal ObjectPipeline path.
 *
 * @internal
 */
final readonly class PlainConstructorFastPathPlanner
{
    public function __construct(
        private ObjectPipeline $objects,
        private ParametersResolver $parameters,
    ) {}

    /**
     * @param class-string $class
     * @return list<class-string>|null Required autowired constructor types, or null when generic execution is required.
     */
    public function plan(string $class): ?array
    {
        if (!$this->objects->canUsePlainConstructorFastPath($class)) {
            return null;
        }

        $targets = $this->objects->constructorTargets($class);
        if ($targets === []) {
            return [];
        }

        $requiredTypes = [];
        $defaultsStarted = false;

        foreach ($targets as $target) {
            if ($target->variadic || $target->byReference) {
                return null;
            }

            $resolvers = $this->resolverClasses($target);

            if ($target->hasDefault) {
                $defaultsStarted = true;
                if (!$this->isNativeDefaultOnly($resolvers, $target)) {
                    return null;
                }
                continue;
            }

            if ($defaultsStarted
                || $target->allowsNull
                || $target->className === null
                || $resolvers !== [
                    ArrayResolver::class,
                    ArrayTypedResolver::class,
                    AutowireByTypeResolver::class,
                ]
            ) {
                return null;
            }

            $requiredTypes[] = $target->className;
        }

        return $requiredTypes;
    }

    /** @param list<class-string> $plan */
    public static function fingerprint(array $plan): string
    {
        return hash('sha256', serialize($plan));
    }

    /** @return list<class-string<ParameterResolverInterface>> */
    private function resolverClasses(ParameterTarget $target): array
    {
        $classes = [];
        foreach ($this->parameters->resolverSlotsFor($target) as $slot) {
            $classes[] = $this->parameters->resolverList[$slot]::class;
        }
        return $classes;
    }

    /** @param list<class-string<ParameterResolverInterface>> $resolvers */
    private function isNativeDefaultOnly(array $resolvers, ParameterTarget $target): bool
    {
        $expected = [ArrayResolver::class, DefaultValueResolver::class];
        if ($target->allowsNull) {
            $expected[] = NullableResolver::class;
        }

        return $resolvers === $expected;
    }
}
