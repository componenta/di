<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\EntryId;
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

/** Resolves #[EntryId] parameters and handles #[EntryId] properties. */
final class EntryIdResolver implements ParameterResolverInterface, AttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(EntryId::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(EntryId::class);
        if (!$attribute instanceof EntryId) {
            return null;
        }

        try {
            return [$target->position, $this->container->get($attribute->value)];
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
        if (!$attribute instanceof EntryId || !$target instanceof ReflectionProperty) {
            throw new LogicException('EntryIdResolver received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $context->writeProperty($target, $this->container->get($attribute->value));
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }
}
