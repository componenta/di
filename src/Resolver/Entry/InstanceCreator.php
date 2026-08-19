<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;

/** Creates or initializes an object through the parameter resolver chain. */
final readonly class InstanceCreator
{
    public function __construct(private ParametersResolver $parameters) {}

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int, mixed> $params
     * @return T
     */
    public function create(ReflectionClass $class, array $params = []): object
    {
        $constructor = $class->getConstructor();
        return $this->createPrepared(
            $class,
            $constructor,
            $constructor === null ? [] : $this->targets($constructor),
            $params,
        );
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param list<ParameterTarget> $targets
     * @param array<string|int, mixed> $params
     * @return T
     */
    public function createPrepared(
        ReflectionClass $class,
        ?ReflectionMethod $constructor,
        array $targets,
        array $params = [],
    ): object {
        if ($constructor === null) {
            return $class->newInstance();
        }

        return $class->newInstanceArgs(
            $this->parameters->resolveTargets($targets, $params),
        );
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<string|int, mixed> $params
     */
    public function initialize(object $entry, ReflectionClass $class, array $params = []): void
    {
        $constructor = $class->getConstructor();
        $this->initializePrepared(
            $entry,
            $constructor,
            $constructor === null ? [] : $this->targets($constructor),
            $params,
        );
    }

    /**
     * @param list<ParameterTarget> $targets
     * @param array<string|int, mixed> $params
     */
    public function initializePrepared(
        object $entry,
        ?ReflectionMethod $constructor,
        array $targets,
        array $params = [],
    ): void {
        if ($constructor === null) {
            return;
        }

        $constructor->invokeArgs(
            $entry,
            $this->parameters->resolveTargets($targets, $params),
        );
    }

    /** @return list<ParameterTarget> */
    public function targets(ReflectionMethod $constructor): array
    {
        return $this->parameters->targets($constructor->getParameters());
    }
}
