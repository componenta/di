<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ResolutionException;
use ReflectionClass;

/**
 * Runs post-construction hooks (e.g. {@see \Componenta\DI\Attribute\SetUp} methods)
 * on a fully-constructed instance.
 *
 * Kept as its own contract so alternative post-init strategies (lifecycle
 * observers, no-op, compiled schedules) can replace the default runner
 * without touching {@see ReflectionResolver}.
 */
interface PostInitializerInterface
{
    /**
     * @param array<string, mixed> $context Resolution context (merged with
     *                                      per-SetUp param values).
     *
     * @throws ResolutionException If a post-init step fails to resolve its
     *                             arguments.
     */
    public function run(ReflectionClass $reflector, object $entry, array $context = []): void;
}
