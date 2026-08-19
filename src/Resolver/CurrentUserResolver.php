<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Authoritative current-user source for parameters and properties. */
final class CurrentUserResolver implements ParameterResolverInterface, AttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(CurrentUser::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(CurrentUser::class);
        if (!$attribute instanceof CurrentUser) {
            return null;
        }

        try {
            return [$target->position, $this->user($attribute, $target->allowsNull)];
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
            throw new LogicException('CurrentUserResolver received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $context->writeProperty($target, $this->user($attribute, $target->getType()?->allowsNull() ?? true));
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
