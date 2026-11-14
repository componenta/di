<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;

/**
 * Validates and executes explicit definitions without owning registrations.
 *
 * Classification must not resolve services or invoke application callbacks.
 * Validation returns stable configuration for this strategy, without publishing
 * or retaining an id-to-definition map. Execution receives that exact validated
 * descriptor; direct callers must validate their input before executing it.
 */
interface DefinitionResolverInterface
{
    public function supportsDefinition(DefinitionInterface $definition): bool;

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface;

    /** @param array<string|int, mixed> $params */
    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed;
}
