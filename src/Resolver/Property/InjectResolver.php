<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Property;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\AttributeDrivenResolverInterface;
use Componenta\DI\Resolver\TypeHints;
use Componenta\Reflection\Reflection;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Resolves properties marked with {@see Inject} by looking up the property's
 * type in the container.
 *
 * Property-only attribute: parameters use {@see AutowireByTypeResolver} for
 * the same effect implicitly.
 *
 * @example
 * ```php
 * class Service {
 *     #[Inject]
 *     private LoggerInterface $logger;
 * }
 * ```
 */
final class InjectResolver implements
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface
{
    public const string KIND = 'componenta.di.inject';

    public function __construct(
        private readonly ContainerInterface $container,
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
        return $target->getAttributes(Inject::class) !== [] ? self::KIND : null;
    }

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $inject = Reflection::getFirstMetadata($property, Inject::class);

        if ($inject === null) {
            return null;
        }

        $typeName = TypeHints::classOf($property->getType());

        if ($typeName === null) {
            throw ResolutionException::forProperty(
                $property,
                reason: '#[Inject] requires a class-typed property',
            );
        }

        try {
            return [$property, $this->container->get($typeName)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }
}
