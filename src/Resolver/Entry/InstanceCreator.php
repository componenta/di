<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Reflection\Reflection;
use ReflectionClass;

/**
 * Creates a raw instance of a class with resolved constructor parameters.
 *
 * Handles two instantiation modes:
 *  - normal: call the constructor with auto-resolved parameters
 *  - {@see NoConstructor}: allocate the instance without calling any constructor
 *
 * Used by {@see ReflectionResolver} both for eager creation and for lazy-ghost
 * initialization (see {@see self::initialize()}).
 */
final readonly class InstanceCreator implements InstantiatorInterface
{
    public function __construct(
        private ParametersResolver $parametersResolver,
    ) {}

    /**
     * Creates a fresh instance.
     *
     * @param array<string, mixed> $context Context forwarded to parameter resolvers.
     */
    public function create(ReflectionClass $reflector, array $context = []): object
    {
        if (Reflection::hasMetadata($reflector, NoConstructor::class)) {
            return $reflector->newInstanceWithoutConstructor();
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return $reflector->newInstance();
        }

        $params = $this->parametersResolver->resolve($constructor->getParameters(), $context);

        return $reflector->newInstanceArgs($params);
    }

    /**
     * Calls the constructor on an already-allocated instance.
     *
     * Used by the lazy-ghost proxy path: PHP allocates the shell via
     * {@see ReflectionClass::newLazyGhost()}; on first access we still need to
     * run the constructor on that existing object.
     *
     * @param array<string, mixed> $context Context forwarded to parameter resolvers.
     */
    public function initialize(object $entry, ReflectionClass $reflector, array $context = []): void
    {
        if (Reflection::hasMetadata($reflector, NoConstructor::class)) {
            return;
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return;
        }

        $params = $this->parametersResolver->resolve($constructor->getParameters(), $context);
        $entry->__construct(...$params);
    }
}
