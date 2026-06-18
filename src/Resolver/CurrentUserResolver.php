<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\PropertyTarget;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Injects the authenticated user (via {@see CurrentUserProviderInterface})
 * into parameters or properties marked with {@see CurrentUser}.
 *
 * Respects nullable declarations (returns `null` when no user is available
 * and the target permits null) and enforces both the attribute's type
 * constraint and the target's declared type.
 *
 * The provider is fetched from the container on every resolution instead of
 * cached on the resolver: the container's {@see \Componenta\DI\EntryCache} already
 * memoises singletons, so we save nothing by caching here, and an immutable
 * resolver keeps authentication lookups honest if the provider binding is
 * ever swapped at runtime.
 *
 * @example Parameter
 * ```php
 * public function updateProfile(
 *     #[CurrentUser] User $user,
 *     ProfileDTO $data,
 * ): void {}
 * ```
 *
 * @example Optional parameter
 * ```php
 * public function view(#[CurrentUser] ?User $user): Response { ... }
 * ```
 *
 * @example Property
 * ```php
 * class AdminController {
 *     #[CurrentUser(Admin::class)]
 *     private Admin $admin;
 * }
 * ```
 */
final readonly class CurrentUserResolver implements
    ParameterResolverInterface,
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface
{
    public const string KIND = 'componenta.di.current_user';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        return $target->getAttributes(CurrentUser::class) !== [] ? self::KIND : null;
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $target    = new ParameterTarget($parameter);
        $attribute = $target->getFirstAttribute(CurrentUser::class);

        if ($attribute === null) {
            return null;
        }

        $user = $this->provider()->getUser();

        if ($user === null) {
            if ($target->allowsNull()) {
                return [$parameter->getPosition(), null];
            }

            throw ResolutionException::forParameter(
                $parameter,
                reason: 'current user is required but not authenticated',
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        $this->assertUserType($user, $attribute, $target);

        return [$parameter->getPosition(), $user];
    }

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $target    = new PropertyTarget($property);
        $attribute = $target->getFirstAttribute(CurrentUser::class);

        if ($attribute === null) {
            return null;
        }

        $user = $this->provider()->getUser();

        if ($user === null) {
            if ($target->allowsNull()) {
                return [$property, null];
            }

            throw ResolutionException::forProperty(
                $property,
                reason: 'current user is required but not authenticated',
            );
        }

        $this->assertUserType($user, $attribute, $target);

        return [$property, $user];
    }

    private function provider(): CurrentUserProviderInterface
    {
        return $this->container->get(CurrentUserProviderInterface::class);
    }

    /**
     * Validates the resolved user against both the attribute constraint and
     * the target's declared type.
     *
     * @throws ResolutionException If either check fails.
     */
    private function assertUserType(
        object $user,
        CurrentUser $attribute,
        InjectionTargetInterface $target,
    ): void {
        if ($attribute->type !== null && !$user instanceof $attribute->type) {
            $this->throwTypeMismatch($target, $attribute->type, $user::class);
        }

        $type = $target->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $typeName = $type->getName();
        if ($typeName !== 'object' && !$user instanceof $typeName) {
            $this->throwTypeMismatch($target, $typeName, $user::class);
        }
    }

    /**
     * @throws ResolutionException
     */
    private function throwTypeMismatch(
        InjectionTargetInterface $target,
        string $expected,
        string $actual,
    ): void {
        $reason    = sprintf('current user must be instance of "%s", got "%s"', $expected, $actual);
        $reflector = $target->getReflector();

        if ($reflector instanceof ReflectionParameter) {
            throw ResolutionException::forParameter($reflector, reason: $reason);
        }

        throw ResolutionException::forProperty($reflector, reason: $reason);
    }
}
