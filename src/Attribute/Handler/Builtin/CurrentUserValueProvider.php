<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class CurrentUserValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::RejectExplicit;
    }

    public function __construct(private ContainerInterface $container) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof CurrentUser) {
            throw new LogicException('CurrentUserValueProvider received an unsupported attribute.');
        }

        $provider = $this->container->get(CurrentUserProviderInterface::class);
        if (!$provider instanceof CurrentUserProviderInterface) {
            throw new LogicException(sprintf('Container entry %s has an invalid type.', CurrentUserProviderInterface::class));
        }

        $user = $provider->getUser();
        if ($user === null) {
            if ($target->allowsNull) {
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
