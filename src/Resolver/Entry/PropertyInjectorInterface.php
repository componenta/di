<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ResolutionException;
use ReflectionClass;

/**
 * Writes attribute-driven values into an already-constructed instance.
 *
 * Implementations decide the policy (skip promoted/readonly/static, respect
 * declared defaults, etc.) and delegate the actual value resolution to a
 * property resolver chain.
 */
interface PropertyInjectorInterface
{
    /**
     * @param array<string, mixed> $context Resolution context.
     *
     * @throws ResolutionException If a property resolver fails for a declared
     *                             property.
     */
    public function inject(ReflectionClass $reflector, object $entry, array $context = []): void;
}
