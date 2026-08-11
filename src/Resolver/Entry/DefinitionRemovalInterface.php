<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Optional capability for resolvers that can remove definitions registered at runtime.
 *
 * Kept separate from {@see DefinitionAwareResolverInterface} so third-party
 * resolvers that only support registration do not receive a backwards-
 * incompatible interface method. Containers use this capability when a
 * runtime definition is later replaced by a stored value.
 */
interface DefinitionRemovalInterface
{
    /**
     * Removes the definition currently registered for the id, if present.
     *
     * @throws InvalidConfigurationException If the resolver cannot safely
     *                                       remove a runtime definition.
     */
    public function removeDefinition(string $id): void;
}
