<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Init;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Executes #[Init] and writes its result to the attributed property. */
final class InitHandler implements AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 600;
    }

    public function __construct(
        private readonly CallableInvokerInterface $callableInvoker,
    ) {}

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, Init::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof Init || !$target instanceof ReflectionProperty) {
            throw new LogicException('InitHandler received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target, allowPromoted: true)) {
            return;
        }

        try {
            $value = $this->callableInvoker->call(
                $attribute->callable,
                $attribute->params,
            );
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }

        $context->writeProperty($target, $value);
    }
}
