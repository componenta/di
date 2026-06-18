<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\TypeHints;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Autowires a parameter by its declared class/interface type, provided that
 * the container knows about it.
 *
 * Applies to any parameter whose type is a non-builtin class or interface.
 * Returns null if the container has no matching entry, deferring to the next
 * resolver in the chain (default value, nullable, etc).
 *
 * @example
 * ```php
 * public function __construct(
 *     LoggerInterface $logger, // Auto-resolved from container
 * ) {}
 * ```
 */
final class AutowireByTypeResolver implements
    ParameterResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface
{
    public const string KIND = 'componenta.di.autowire';

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        if (!$target instanceof ReflectionParameter) {
            return null;
        }
        return TypeHints::classOf($target->getType()) !== null ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        return [
            'type' => $target instanceof ReflectionParameter
                ? TypeHints::classOf($target->getType())
                : null,
        ];
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        return $this->resolveType(
            TypeHints::classOf($parameter->getType()),
            $parameter,
            $providedParameters,
            $resolvedParameters,
        );
    }

    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        if (!is_array($payload) || !is_string($payload['type'] ?? null)) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        return $this->resolveType($payload['type'], $parameter, $providedParameters, $resolvedParameters);
    }

    /**
     * @param class-string|null $typeName
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed> $resolvedParameters
     * @return array{0: int, 1: mixed}|null
     */
    private function resolveType(
        ?string $typeName,
        ReflectionParameter $parameter,
        array $providedParameters,
        array $resolvedParameters,
    ): ?array {
        if ($typeName === null || !$this->container->has($typeName)) {
            return null;
        }

        try {
            return [$parameter->getPosition(), $this->container->get($typeName)];
        } catch (Throwable $e) {
            // Autowire failed (e.g. system class with required deps the
            // container cannot satisfy: DateTimeImmutable -> DateTimeZone).
            // If the parameter has an explicit default, defer to the chain
            // so DefaultValueResolver picks it up.
            if ($parameter->isDefaultValueAvailable()) {
                return null;
            }

            if ($e instanceof ContainerExceptionInterface) {
                throw $e;
            }

            throw ResolutionException::forParameter(
                $parameter,
                previous: $e,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }
    }
}
