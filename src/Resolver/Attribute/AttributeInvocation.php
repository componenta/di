<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use ReflectionAttribute;
use Reflector;

/** Immutable, pre-bound invocation metadata for one attribute handler. */
final readonly class AttributeInvocation
{
    /**
     * Attribute objects are never cached. Both runtime execution and code
     * generation instantiate from the native reflector so mutable custom
     * attributes cannot leak state across creations or compiler runs.
     *
     * @param class-string $attributeClass
     * @param ReflectionAttribute<object> $reflectionAttribute
     */
    public function __construct(
        public AttributeHandlerInterface $handler,
        public int $handlerSlot,
        public ReflectionAttribute $reflectionAttribute,
        public string $attributeClass,
        public int $attributeIndex,
        public Reflector $target,
        public int $priority,
        public int $declarationOrder,
    ) {}

    public function newAttribute(): object
    {
        return $this->reflectionAttribute->newInstance();
    }
}
