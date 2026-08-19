<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use ReflectionAttribute;
use Reflector;

/** One composed DI attribute usage together with its semantic definition. */
final readonly class AttributeUsage
{
    /** @param ReflectionAttribute<object> $reflection */
    public function __construct(
        public ReflectionAttribute $reflection,
        public object $attribute,
        public AttributeDefinition $definition,
        public Reflector $target,
        public int $declarationOrder,
    ) {}

    /** Creates an isolated runtime attribute instance for one handler invocation. */
    public function newInstance(): object
    {
        return $this->reflection->newInstance();
    }
}
