<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;

/** Creates or initializes an object through the standard parameter pipeline. */
final readonly class InstanceCreator
{
    public function __construct(
        private ParametersResolver $parametersResolver,
    ) {}

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflector
     * @param array<string|int, mixed> $context
     * @return T
     */
    public function create(ReflectionClass $reflector, array $context = []): object
    {
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return $reflector->newInstance();
        }

        $params = $this->parametersResolver->resolve($constructor->getParameters(), $context);

        return $reflector->newInstanceArgs($params);
    }

    /**
     * Calls the constructor on an already-allocated lazy ghost.
     *
     * Reflection invocation is intentional: it preserves the constructor's
     * declared visibility while initializing the already allocated instance.
     *
     * @param ReflectionClass<object> $reflector
     * @param array<string|int, mixed> $context
     */
    public function initialize(object $entry, ReflectionClass $reflector, array $context = []): void
    {
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return;
        }

        $params = $this->parametersResolver->resolve($constructor->getParameters(), $context);
        $constructor->invokeArgs($entry, $params);
    }
}
