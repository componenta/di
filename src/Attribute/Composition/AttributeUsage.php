<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Reflector;

/** One instantiated DI attribute together with its semantic definition. */
final readonly class AttributeUsage
{
    public function __construct(
        public object $attribute,
        public AttributeDefinition $definition,
        public Reflector $target,
        public int $declarationOrder,
    ) {}
}
