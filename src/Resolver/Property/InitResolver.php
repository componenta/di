<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Property;

use Componenta\DI\Attribute\Init;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\AttributeDrivenResolverInterface;
use Componenta\Reflection\Reflection;
use Psr\Container\ContainerExceptionInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Resolves properties using #[Init] attribute by executing callables.
 *
 * Executes callables once during initialization to generate property values.
 * Supports:
 * - Closures: fn() => value
 * - Static methods: [Class::class, 'method']
 * - Instance methods (resolved from container)
 * - Function names: 'time', 'uniqid'
 * - Invokable classes
 *
 * @example
 * ```php
 * class EventDTO {
 *     #[Init([Carbon::class, 'now'])]
 *     public Carbon $createdAt;
 *
 *     #[Init([Uuid::class, 'uuid4'])]
 *     public UuidInterface $id;
 *
 *     #[Init(fn() => random_int(1000, 9999))]
 *     public int $code;
 *
 *     #[Init('time')]
 *     public int $timestamp;
 * }
 * ```
 */
final readonly class InitResolver implements
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface
{
    public const string KIND = 'componenta.di.init';

    public function __construct(
        private CallableInvokerInterface $callableInvoker,
    ) {}

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        if (!$target instanceof ReflectionProperty) {
            return null;
        }
        return $target->getAttributes(Init::class) !== [] ? self::KIND : null;
    }

    /**
     * @throws ResolutionException
     */
    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $init = Reflection::getFirstMetadata($property, Init::class);

        if ($init === null) {
            return null;
        }

        try {
            $value = $this->callableInvoker->call($init->callable, $init->params);

            return [$property, $value];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }
}
