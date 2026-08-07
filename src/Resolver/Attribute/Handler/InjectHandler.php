<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\TypeHints;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Resolves a class-typed #[Inject] property from the container. */
final class InjectHandler implements AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 200;
    }

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, Inject::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof Inject || !$target instanceof ReflectionProperty) {
            throw new LogicException('InjectHandler received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        $typeName = TypeHints::classOf($target->getType(), $target->getDeclaringClass());
        if ($typeName === null) {
            throw ResolutionException::forProperty(
                $target,
                reason: '#[Inject] requires a class-typed property',
            );
        }

        try {
            $value = $this->container->get($typeName);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }

        $context->writeProperty($target, $value);
    }
}
