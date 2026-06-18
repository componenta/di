<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Compile\PropertyPlanResolverInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\PropertyTarget;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Resolves parameters and properties marked with {@see EntryId} by looking up
 * the given identifier in the container.
 *
 * @example
 * ```php
 * public function __construct(
 *     #[EntryId('cache.redis')] CacheInterface $cache,
 * ) {}
 *
 * class Service {
 *     #[EntryId('cache.redis')] private CacheInterface $cache;
 * }
 * ```
 */
final readonly class EntryIdResolver implements
    ParameterResolverInterface,
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface,
    PropertyPlanResolverInterface
{
    public const string KIND = 'componenta.di.entry_id';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        return $target->getAttributes(EntryId::class) !== [] ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        $attribute = $target->getAttributes(EntryId::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        /** @var EntryId $entryId */
        $entryId = $attribute->newInstance();

        return ['id' => $entryId->value];
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $entryId = (new ParameterTarget($parameter))->getFirstAttribute(EntryId::class);
        if ($entryId === null) {
            return null;
        }

        return $this->resolveParameterEntryId($parameter, $entryId->value, $providedParameters, $resolvedParameters);
    }

    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $entryId = $this->entryIdFromPayload($payload);
        if ($entryId === null) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        return $this->resolveParameterEntryId($parameter, $entryId, $providedParameters, $resolvedParameters);
    }

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $entryId = (new PropertyTarget($property))->getFirstAttribute(EntryId::class);
        if ($entryId === null) {
            return null;
        }

        return $this->resolvePropertyEntryId($property, $entryId->value);
    }

    public function resolvePropertyPlan(
        ReflectionProperty $property,
        mixed $payload,
        array $context = [],
    ): ?array {
        $entryId = $this->entryIdFromPayload($payload);
        if ($entryId === null) {
            return $this->resolveProperty($property, $context);
        }

        return $this->resolvePropertyEntryId($property, $entryId);
    }

    private function entryIdFromPayload(mixed $payload): ?string
    {
        return is_array($payload) && is_string($payload['id'] ?? null)
            ? $payload['id']
            : null;
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed> $resolvedParameters
     * @return array{0: int, 1: mixed}
     */
    private function resolveParameterEntryId(
        ReflectionParameter $parameter,
        string $entryId,
        array $providedParameters,
        array $resolvedParameters,
    ): array {
        try {
            return [$parameter->getPosition(), $this->container->get($entryId)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                previous: $e,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }
    }

    /**
     * @return array{0: ReflectionProperty, 1: mixed}
     */
    private function resolvePropertyEntryId(ReflectionProperty $property, string $entryId): array
    {
        try {
            return [$property, $this->container->get($entryId)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }
}
