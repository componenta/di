<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use Reflector;

/** Selects virtual-proxy construction for class-level #[Proxy]. */
final class ProxyHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof Proxy || !$target instanceof ReflectionClass) {
            throw new \LogicException('ProxyHandler received an unsupported attribute target.');
        }
        if ($attribute->class !== null) {
            throw new \LogicException('Class-level #[Proxy] must not specify a proxy class; the marked class is used.');
        }
        $context->selectStrategy(CreationStrategy::Proxy);
    }
}
