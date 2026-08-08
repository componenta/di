<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\EntryId;
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

/** Resolves #[EntryId] parameters and handles #[EntryId] properties. */
final class EntryIdResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 300;
    }

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(EntryId::class);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, EntryId::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof EntryId || !$target instanceof ReflectionProperty) {
            throw new LogicException('EntryIdResolver received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $value = $this->container->get($attribute->value);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }

        $context->writeProperty($target, $value);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $entryId = $target->firstAttribute(EntryId::class);
        if ($entryId === null) {
            return null;
        }

        return $this->resolveParameterEntryId(
            $target->reflection,
            $entryId->value,
            $context->provided,
            $context->resolved,
        );
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed>        $resolvedParameters
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
}
