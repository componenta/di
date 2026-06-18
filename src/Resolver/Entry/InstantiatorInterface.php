<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ResolutionException;
use ReflectionClass;

/**
 * Creates raw instances of a class with resolved constructor parameters.
 *
 * Split from the rest of the resolution pipeline (property injection, SetUp,
 * proxy creation) so the "how do we get an object" step is a single
 * substitutable concern.
 */
interface InstantiatorInterface
{
    /**
     * Creates a fresh instance.
     *
     * @param array<string, mixed> $context Resolution context (passed through
     *                                      to parameter resolvers).
     *
     * @throws ResolutionException If a constructor parameter cannot be resolved.
     */
    public function create(ReflectionClass $reflector, array $context = []): object;

    /**
     * Runs the constructor on an already-allocated instance. Used by the
     * lazy-ghost path, where PHP allocates the shell and the initializer has
     * to populate it.
     *
     * @param array<string, mixed> $context Resolution context.
     *
     * @throws ResolutionException If a constructor parameter cannot be resolved.
     */
    public function initialize(object $entry, ReflectionClass $reflector, array $context = []): void;
}
