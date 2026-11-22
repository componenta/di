<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;

/** Entry operations for the native container and the internal lookup. @internal */
abstract class ContainerEntryOperations
{
    /** @param array<array-key,mixed> $bootstrapServices */
    abstract protected function assemble(
        EntryResolverInterface $resolver,
        AliasResolver $aliases,
        CallableExecutorInterface $callableExecutor,
        ?EntryCache $cache = null,
        ?DelegatorRegistry $delegators = null,
        ?ExternalContainerRegistry $externalContainers = null,
        ?CycleGuard $cycleGuard = null,
        ?ProxyFactoryInterface $proxyFactory = null,
        array $bootstrapServices = [],
    ): void;

    abstract protected function hasEntry(string $id, bool $requireResolvable): bool;

    abstract protected function getEntry(string $id, bool $requireResolvable): mixed;

    /** @param array<string|int,mixed>|EntryParameters $params */
    abstract protected function makeEntry(string $entry, array|EntryParameters $params): object;
}
