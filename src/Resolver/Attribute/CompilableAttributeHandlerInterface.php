<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\GeneratedAttributeCode;
use Reflector;

/** Attribute handler that can specialize its pre-bound runtime invocation. */
interface CompilableAttributeHandlerInterface extends AttributeHandlerInterface
{
    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode;
}
