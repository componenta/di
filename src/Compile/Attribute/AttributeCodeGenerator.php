<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Attribute;

use Componenta\DI\Resolver\Attribute\AttributeInvocation;
use Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface;

/** Compiles one pre-bound invocation without knowing concrete attributes. */
final readonly class AttributeCodeGenerator
{
    public function generate(
        AttributeInvocation $invocation,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        if ($invocation->handler instanceof CompilableAttributeHandlerInterface) {
            return $invocation->handler->generateAttributeCode(
                $invocation->newAttribute(),
                $invocation->target,
                $context,
            );
        }

        // Unknown handlers remain fully functional. The generated factory
        // skips class scanning and dispatches directly to the exact slot.
        return new GeneratedAttributeCode(
            code: sprintf(
                '%s->handle(%s, %s, %s);',
                $context->handlerExpression,
                $context->attributeExpression,
                $context->targetExpression,
                $context->creationExpression,
            ),
            usesAttribute: true,
            usesTarget: true,
        );
    }
}
