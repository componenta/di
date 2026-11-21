<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
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
     * @param Closure():void|null $beforeConstructor
     * @return T
     */
    public function createPrepared(
        ReflectionClass $class,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params = [],
        ?Closure $beforeConstructor = null,
    ): object {
        $entry = $this->createTracked($class, $constructor, $plan, $params, $beforeConstructor, $proof);
        $proof?->assertLoadedParametersUnchanged();
        $proof?->assertParameterSourcesUnchanged();
        return $entry;
    }

    /**
     * @internal
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int, mixed> $params
     * @param Closure():void|null $beforeConstructor
     * @return T
     */
    public function createTracked(
        ReflectionClass $class,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params,
        ?Closure $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
    ): object {
        if ($constructor === null) {
            $proof = null;
            $beforeConstructor?->__invoke();
            try {
                return $class->newInstance();
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw ResolutionException::forService($class->getName(), $e);
            }
        }

        $arguments = $this->parameters->resolveTracked($plan, $params, $proof);
        $beforeConstructor?->__invoke();
        // The final policy check can reveal a source after argument resolution.
        MappedRequestParameterSourceGuard::assertTargetsContextNoConflicts($plan->targets, $params, autoload: false);
        $proof?->assertLoadedParametersUnchanged();

        try {
            return $class->newInstanceArgs($arguments);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class->getName(), $e);
        }
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
        $targets = $constructor === null ? [] : $this->targets($constructor);
        $this->initializePrepared(
            $entry,
            $constructor,
            $this->parameters->prepareTargets($targets),
            $params,
        );
    }

    /**
     * @param array<string|int, mixed> $params
     * @param Closure():void|null $beforeConstructor
     */
    public function initializePrepared(
        object $entry,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params = [],
        ?Closure $beforeConstructor = null,
    ): void {
        $this->initializeTracked($entry, $constructor, $plan, $params, $beforeConstructor, $proof);
        $proof?->assertLoadedParametersUnchanged();
        $proof?->assertParameterSourcesUnchanged();
    }

    /**
     * @internal
     * @param array<string|int, mixed> $params
     * @param Closure():void|null $beforeConstructor
     */
    public function initializeTracked(
        object $entry,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params,
        ?Closure $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
    ): void {
        if ($constructor === null) {
            $proof = null;
            return;
        }

        $arguments = $this->parameters->resolveTracked($plan, $params, $proof);
        $beforeConstructor?->__invoke();
        MappedRequestParameterSourceGuard::assertTargetsContextNoConflicts($plan->targets, $params, autoload: false);
        $proof?->assertLoadedParametersUnchanged();

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
