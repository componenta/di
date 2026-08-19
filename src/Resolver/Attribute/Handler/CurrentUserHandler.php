<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Authoritative current-user handler for parameters and properties. */
final class CurrentUserHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed {
        if (!$attribute instanceof CurrentUser) {
            throw new LogicException('CurrentUserHandler received an unsupported parameter attribute.');
        }

        try {
            return $this->user($attribute, $target->allowsNull);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                previous: $e,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof CurrentUser || !$target instanceof ReflectionProperty) {
            throw new LogicException('CurrentUserHandler received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $context->writeProperty(
                $target,
                $this->user($attribute, $target->getType()?->allowsNull() ?? true),
            );
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }

    private function user(CurrentUser $attribute, bool $allowsNull): mixed
    {
        $provider = $this->container->get(CurrentUserProviderInterface::class);
        if (!$provider instanceof CurrentUserProviderInterface) {
            throw new LogicException(sprintf(
                'Container entry %s has an invalid type.',
                CurrentUserProviderInterface::class,
            ));
        }

        $user = $provider->getUser();
        if ($user === null) {
            if ($allowsNull) {
                return null;
            }
            throw new LogicException('Current user is required but no authenticated user is available.');
        }

        if ($attribute->type !== null && !$user instanceof $attribute->type) {
            throw new LogicException(sprintf(
                'Current user must be an instance of %s; got %s.',
                $attribute->type,
                $user::class,
            ));
        }

        return $user;
    }
}
