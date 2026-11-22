<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Runs one native constructor step and leaves its proof to the caller that owns the remaining lifecycle.
 *
 * @internal
 */
final class ConstructorInvocation
{
    /**
     * @template T of object
     * @param ReflectionClass<covariant T> $class
     * @param array<string|int, mixed> $params
     * @param Closure():void|null $beforeConstructor
     * @return T
     */
    public static function create(
        ParameterExecution $parameters,
        ReflectionClass $class,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params,
        ?Closure $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
        ?MappedRequestContext $provenance = null,
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

        $arguments = self::arguments($parameters, $plan, $params, $beforeConstructor, $proof, $provenance);

        try {
            return $class->newInstanceArgs($arguments);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class->getName(), $e);
        }
    }

    /**
     * @param array<string|int, mixed> $params
     * @param Closure():void|null $beforeConstructor
     */
    public static function initialize(
        ParameterExecution $parameters,
        object $entry,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params,
        ?Closure $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
        ?MappedRequestContext $provenance = null,
    ): void {
        if ($constructor === null) {
            $proof = null;
            return;
        }

        $arguments = self::arguments($parameters, $plan, $params, $beforeConstructor, $proof, $provenance);

        try {
            $constructor->invokeArgs($entry, $arguments);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($constructor->getDeclaringClass()->getName(), $e);
        }
    }

    /**
     * @param array<string|int, mixed> $params
     * @param Closure():void|null $beforeConstructor
     * @return array<array-key, mixed>
     */
    private static function arguments(
        ParameterExecution $parameters,
        PreparedParameterPlan $plan,
        array $params,
        ?Closure $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
        ?MappedRequestContext $provenance,
    ): array {
        $arguments = $parameters->resolveTracked($plan, $params, $proof, $provenance);
        $beforeConstructor?->__invoke();
        // Argument resolution and the callback can reveal a new parameter source.
        MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts($plan->targets, $provenance, autoload: false);
        $proof?->assertLoadedParametersUnchanged();

        return $arguments;
    }
}
