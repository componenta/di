<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\MakeAttributeResolver;
use ReflectionClass;
use ReflectionProperty;
use Reflector;

/** Handles class-level proxy strategy and property-level proxy injection. */
final class ProxyHandler implements AttributeHandlerInterface
{
    public function __construct(private readonly MakeAttributeResolver $makeResolver) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof Proxy) {
            throw new \LogicException('ProxyHandler received an unsupported attribute.');
        }

        if ($target instanceof ReflectionProperty) {
            $this->makeResolver->handle($attribute, $target, $context);
            return;
        }

        if (!$target instanceof ReflectionClass) {
            throw new \LogicException('ProxyHandler received an unsupported attribute target.');
        }
        if ($attribute->class !== null) {
            throw new \LogicException('Class-level #[Proxy] must not specify a proxy class; the marked class is used.');
        }

        $context->selectStrategy(CreationStrategy::Proxy);
    }
}
