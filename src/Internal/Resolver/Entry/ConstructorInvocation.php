<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\ReflectionSnapshot;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Builds and runs one native constructor step and leaves its proof to the caller that owns the remaining lifecycle.
 *
 * @internal
 */
final class ConstructorInvocation
{
    /**
     * @param ReflectionClass<covariant object>|null $metadataSource
     * @return list<ParameterTarget>
     */
    public static function targets(
        ParameterExecution $parameters,
        ?ReflectionMethod $constructor,
        ?ReflectionClass $metadataSource,
    ): array {
        if ($constructor === null) {
            return [];
        }

        $native = $constructor->getParameters();
        $borrowed = $metadataSource?->getConstructor();
        if ($borrowed === null || !ReflectionSnapshot::sameDeclaration($borrowed, $constructor)) {
            return $parameters->targets($native);
        }

        $candidates = $borrowed->getParameters();
        if ($candidates === [] || !ReflectionSnapshot::sameDeclaration($borrowed, $constructor)) {
            return $parameters->targets($native);
        }

        $targets = [];
        foreach ($native as $position => $parameter) {
            // Reading one parameter can retarget a later borrowed reflector.
            $candidate = $candidates[$position] ?? null;
            if ($candidate !== null && ReflectionSnapshot::sameParameter($candidate, $parameter)) {
                $parameter = $candidate;
            }
            $targets[] = $parameters->target($parameter);
        }
        return $targets;
    }

    /**
     * @template T of object
     * @param ReflectionClass<covariant T> $class
     * @param array<string|int, mixed> $params
     * @param CompletedAttributeGuard|(Closure():void)|null $beforeConstructor
     * @return T
     */
    public static function create(
        ParameterExecution $parameters,
        ReflectionClass $class,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params,
        CompletedAttributeGuard|Closure|null $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
        ?MappedRequestContext $provenance = null,
    ): object {
        if ($constructor === null) {
            $proof = null;
            self::checkBeforeConstructor($beforeConstructor);
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
     * @param CompletedAttributeGuard|(Closure():void)|null $beforeConstructor
     */
    public static function initialize(
        ParameterExecution $parameters,
        object $entry,
        ?ReflectionMethod $constructor,
        PreparedParameterPlan $plan,
        array $params,
        CompletedAttributeGuard|Closure|null $beforeConstructor,
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
     * @param CompletedAttributeGuard|(Closure():void)|null $beforeConstructor
     * @return array<array-key, mixed>
     */
    private static function arguments(
        ParameterExecution $parameters,
        PreparedParameterPlan $plan,
        array $params,
        CompletedAttributeGuard|Closure|null $beforeConstructor,
        ?CompletedAttributeGuard &$proof,
        ?MappedRequestContext $provenance,
    ): array {
        $arguments = $parameters->resolveTracked($plan, $params, $proof, $provenance);
        self::checkBeforeConstructor($beforeConstructor);
        // Argument resolution and the callback can reveal a new parameter source.
        $completedBefore = $beforeConstructor instanceof CompletedAttributeGuard ? $beforeConstructor : null;
        do {
            MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts(
                $provenance === null ? $plan->targets : $plan->sourceCandidates(),
                $provenance,
                autoload: false,
            );
            $proof?->assertLoadedParametersUnchanged($completedBefore);
        } while ($proof === null && $provenance !== null && $completedBefore?->revalidateLoadedChanges() === true);

        return $arguments;
    }

    /** @param CompletedAttributeGuard|(Closure():void)|null $beforeConstructor */
    private static function checkBeforeConstructor(CompletedAttributeGuard|Closure|null $beforeConstructor): void
    {
        if ($beforeConstructor instanceof CompletedAttributeGuard) {
            $beforeConstructor->assertUnchanged();
        } else {
            $beforeConstructor?->__invoke();
        }
    }
}
