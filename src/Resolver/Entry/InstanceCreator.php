<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Internal\ReflectionSnapshot;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ConstructorInvocation;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionAccess;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;

/** Creates or initializes an object through the parameter resolver chain. */
final readonly class InstanceCreator
{
    private ParameterExecution $parameters;

    public function __construct(ParametersResolver $parameters)
    {
        $this->parameters = ParameterExecutionAccess::for($parameters);
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int, mixed> $params
     * @return T
     */
    public function create(ReflectionClass $class, array $params = []): object
    {
        if ($params !== []) {
            $params = ResolutionMetadata::invocationSlots($params);
        }

        $construction = ReflectionSnapshot::forConstruction($class);
        $constructor = $construction->getConstructor();
        $targets = ConstructorInvocation::targets($this->parameters, $constructor, $class::class === ReflectionClass::class ? null : $class);
        $entry = ConstructorInvocation::create(
            $this->parameters,
            $construction,
            $constructor,
            $this->parameters->prepareTargets($targets),
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
        if ($params !== []) {
            $params = ResolutionMetadata::invocationSlots($params);
        }

        $constructor = ReflectionSnapshot::forConstruction($class)->getConstructor();
        $targets = ConstructorInvocation::targets($this->parameters, $constructor, $class::class === ReflectionClass::class ? null : $class);
        ConstructorInvocation::initialize(
            $this->parameters,
            $entry,
            $constructor,
            $this->parameters->prepareTargets($targets),
            $params,
            null,
            $proof,
        );
        $proof?->assertLoadedParametersUnchanged();
        $proof?->assertParameterSourcesUnchanged();
    }

}
