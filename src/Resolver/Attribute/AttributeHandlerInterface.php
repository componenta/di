<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Reflector;

/**
 * Runtime handler for a class, property or method attribute.
 *
 * Handlers are shared services and therefore must not retain state from an
 * ObjectCreationContext. The context belongs to exactly one creation attempt.
 */
interface AttributeHandlerInterface
{
    public AttributePhase $phase { get; }

    /** Higher priority executes first within the same phase. */
    public int $priority { get; }

    /**
     * Static applicability check performed while the class metadata is built.
     * Implementations must be pure and stable for the lifetime of the handler;
     * the result is cached and shared by reflection and generated factories.
     *
     * @param class-string $attributeClass
     */
    public function supportsAttribute(string $attributeClass, Reflector $target): bool;

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void;
}
