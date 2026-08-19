<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;

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
        if ($constructor === null) {
            return $class->newInstance();
        }

        return $class->newInstanceArgs(
            $this->parameters->resolve($constructor->getParameters(), $params),
        );
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<string|int, mixed> $params
     */
    public function initialize(object $entry, ReflectionClass $class, array $params = []): void
    {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return;
        }

        $constructor->invokeArgs(
            $entry,
            $this->parameters->resolve($constructor->getParameters(), $params),
        );
    }
}
