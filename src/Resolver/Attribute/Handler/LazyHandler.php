<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\GeneratedAttributeCode;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use Reflector;

/** Selects PHP 8.4 lazy-ghost construction for #[Lazy] classes. */
final class LazyHandler implements CompilableAttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::BeforeInstantiation;
    }

    public int $priority {
        get => 100;
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionClass
            && is_a($attributeClass, Lazy::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        self::assertInvocation($attribute, $target);
        $context->selectStrategy(CreationStrategy::Lazy);
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        self::assertInvocation($attribute, $target);

        return new GeneratedAttributeCode(sprintf(
            '%s->selectStrategy(\\%s::Lazy);',
            $context->creationExpression,
            CreationStrategy::class,
        ));
    }

    private static function assertInvocation(object $attribute, Reflector $target): void
    {
        if (!$attribute instanceof Lazy || !$target instanceof ReflectionClass) {
            throw new \LogicException(sprintf(
                '%s received unsupported attribute target %s on %s.',
                self::class,
                $attribute::class,
                get_debug_type($target),
            ));
        }
    }
}
