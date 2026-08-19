<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use Reflector;

/** Disables constructor invocation for classes marked #[NoConstructor]. */
final class NoConstructorHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof NoConstructor || !$target instanceof ReflectionClass) {
            throw new \LogicException('NoConstructorHandler received an unsupported attribute target.');
        }

        $context->disableConstructor();
    }
}
