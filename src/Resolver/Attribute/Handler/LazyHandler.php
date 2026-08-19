<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use Reflector;

/** Selects PHP 8.4 lazy-ghost construction for #[Lazy] classes. */
final class LazyHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof Lazy || !$target instanceof ReflectionClass) {
            throw new \LogicException('LazyHandler received an unsupported attribute target.');
        }
        $context->selectStrategy(CreationStrategy::Lazy);
    }
}
