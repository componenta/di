<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Internal\Resolver\Entry\ConstructorInvocation;
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
        $entry = ConstructorInvocation::create(
            $this->parameters,
            $class,
            $constructor,
            $this->parameters->prepare($constructor?->getParameters() ?? []),
            $params,
            null,
            $proof,
        );
        $proof?->assertLoadedParametersUnchanged();
        $proof?->assertParameterSourcesUnchanged();
        return $entry;
    }

    /**
     * @template T of object
     * @param T $entry
     * @param ReflectionClass<T> $class
     * @param array<string|int, mixed> $params
     */
    public function initialize(object $entry, ReflectionClass $class, array $params = []): void
    {
        $constructor = $class->getConstructor();
        ConstructorInvocation::initialize(
            $this->parameters,
            $entry,
            $constructor,
            $this->parameters->prepare($constructor?->getParameters() ?? []),
            $params,
            null,
            $proof,
        );
        $proof?->assertLoadedParametersUnchanged();
        $proof?->assertParameterSourcesUnchanged();
    }

}
