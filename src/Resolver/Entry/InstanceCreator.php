<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;

/** Creates or initializes an object through the common parameter value pipeline. */
final readonly class InstanceCreator
{
    public function __construct(private ParametersResolver $parameters) {}

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return T
     */
    public function create(
        ReflectionClass $class,
        ResolutionContext $context = new ResolutionContext(),
    ): object {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return $class->newInstance();
        }

        return $class->newInstanceArgs(
            $this->parameters->resolve($constructor->getParameters(), $context),
        );
    }

    /** @param ReflectionClass<object> $class */
    public function initialize(
        object $entry,
        ReflectionClass $class,
        ResolutionContext $context = new ResolutionContext(),
    ): void {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return;
        }

        $constructor->invokeArgs(
            $entry,
            $this->parameters->resolve($constructor->getParameters(), $context),
        );
    }
}
