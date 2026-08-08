<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use Throwable;
use UnexpectedValueException;

/** Injects the authenticated user into #[CurrentUser] targets. */
final class CurrentUserResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 700;
    }

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(CurrentUser::class);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, CurrentUser::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof CurrentUser || !$target instanceof ReflectionProperty) {
            throw new LogicException('CurrentUserResolver received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        $value = $this->resolveUser(
            target: $target,
            attributeType: $attribute->type,
            allowsNull: $target->getType()?->allowsNull() ?? true,
            declaredType: TypeHints::classOf($target->getType(), $target->getDeclaringClass()),
        );

        $context->writeProperty($target, $value);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(CurrentUser::class);
        if ($attribute === null) {
            return null;
        }

        return [
            $target->position,
            $this->resolveUser(
                target: $target->reflection,
                attributeType: $attribute->type,
                allowsNull: $target->allowsNull,
                declaredType: $target->className,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            ),
        ];
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed>        $resolvedParameters
     */
    private function resolveUser(
        ReflectionParameter|ReflectionProperty $target,
        ?string $attributeType,
        bool $allowsNull,
        ?string $declaredType,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?object {
        try {
            $provider = $this->container->get(CurrentUserProviderInterface::class);
            if (!$provider instanceof CurrentUserProviderInterface) {
                throw new UnexpectedValueException(sprintf(
                    'Container entry "%s" must implement %s; got %s.',
                    CurrentUserProviderInterface::class,
                    CurrentUserProviderInterface::class,
                    get_debug_type($provider),
                ));
            }

            $user = $provider->getUser();
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($target instanceof ReflectionParameter) {
                throw ResolutionException::forParameter(
                    $target,
                    previous: $e,
                    providedParameters: $providedParameters,
                    resolvedParameters: $resolvedParameters,
                );
            }

            throw ResolutionException::forProperty($target, previous: $e);
        }

        if ($user === null) {
            if ($allowsNull) {
                return null;
            }

            $reason = 'current user is required but not authenticated';

            if ($target instanceof ReflectionParameter) {
                throw ResolutionException::forParameter(
                    $target,
                    reason: $reason,
                    providedParameters: $providedParameters,
                    resolvedParameters: $resolvedParameters,
                );
            }

            throw ResolutionException::forProperty($target, reason: $reason);
        }

        if ($attributeType !== null && !$user instanceof $attributeType) {
            $this->throwTypeMismatch(
                $target,
                $attributeType,
                $user::class,
                $providedParameters,
                $resolvedParameters,
            );
        }

        if ($declaredType !== null && !$user instanceof $declaredType) {
            $this->throwTypeMismatch(
                $target,
                $declaredType,
                $user::class,
                $providedParameters,
                $resolvedParameters,
            );
        }

        return $user;
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed>        $resolvedParameters
     */
    private function throwTypeMismatch(
        ReflectionParameter|ReflectionProperty $target,
        string $expected,
        string $actual,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): never {
        $reason = sprintf('current user must be instance of "%s", got "%s"', $expected, $actual);

        if ($target instanceof ReflectionParameter) {
            throw ResolutionException::forParameter(
                $target,
                reason: $reason,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        throw ResolutionException::forProperty($target, reason: $reason);
    }
}
