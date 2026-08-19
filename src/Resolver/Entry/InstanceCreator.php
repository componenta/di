<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/** Creates or initializes an object through the parameter resolver chain. */
final readonly class InstanceCreator
{
    public function __construct(private ParametersResolver $parameters) {}

    public function parameters(): ParametersResolver
    {
        return $this->parameters;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int, mixed> $params
     * @return T
     */
    public function create(ReflectionClass $class, array $params = []): object
    {
        $constructor = $class->getConstructor();
        $targets = $constructor === null ? [] : $this->targets($constructor);
        return $this->createPrepared(
            $class,
            $constructor,
            $this->parameters->prepareTargets($targets),
            $params,
        );
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int, mixed> $params
     * @return T
     */
    public function createPrepared(
        ReflectionClass $class,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params = [],
    ): object {
        if ($constructor === null) {
            try {
                return $class->newInstance();
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw ResolutionException::forService($class->getName(), $e);
            }
        }

        $arguments = $this->parameters->resolvePrepared($plan, $params);

        try {
            return $class->newInstanceArgs($arguments);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class->getName(), $e);
        }
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<string|int, mixed> $params
     */
    public function initialize(object $entry, ReflectionClass $class, array $params = []): void
    {
        $constructor = $class->getConstructor();
        $targets = $constructor === null ? [] : $this->targets($constructor);
        $this->initializePrepared(
            $entry,
            $constructor,
            $this->parameters->prepareTargets($targets),
            $params,
        );
    }

    /** @param array<string|int, mixed> $params */
    public function initializePrepared(
        object $entry,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params = [],
    ): void {
        if ($constructor === null) {
            return;
        }

        $arguments = $this->parameters->resolvePrepared($plan, $params);

        try {
            $constructor->invokeArgs($entry, $arguments);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService(
                $constructor->getDeclaringClass()->getName(),
                $e,
            );
        }
    }

    /** @return list<ParameterTarget> */
    public function targets(ReflectionMethod $constructor): array
    {
        return $this->parameters->targets($constructor->getParameters());
    }
}
