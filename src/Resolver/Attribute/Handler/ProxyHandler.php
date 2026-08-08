<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\GeneratedAttributeCode;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use Reflector;

/** Selects virtual-proxy construction for class-level #[Proxy]. */
final class ProxyHandler implements CompilableAttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::BeforeInstantiation;
    }

    public int $priority {
        get => 200;
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionClass
            && is_a($attributeClass, Proxy::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        self::assertInvocation($attribute, $target);
        $context->selectStrategy(CreationStrategy::Proxy);
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        self::assertInvocation($attribute, $target);

        return new GeneratedAttributeCode(sprintf(
            '%s->selectStrategy(\\%s::Proxy);',
            $context->creationExpression,
            CreationStrategy::class,
        ));
    }

    private static function assertInvocation(object $attribute, Reflector $target): void
    {
        if (!$attribute instanceof Proxy || !$target instanceof ReflectionClass) {
            throw new \LogicException(sprintf(
                '%s received unsupported attribute target %s on %s.',
                self::class,
                $attribute::class,
                get_debug_type($target),
            ));
        }
    }
}
