<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use LogicException;

/**
 * One-shot typed hand-off used while the container lazy ghost is being wired.
 *
 * @internal
 */
final class ContainerBootstrapState
{
    private ?EntryResolverInterface $entryResolver = null;

    private ?CallableExecutorInterface $callableExecutor = null;

    public function initialize(
        EntryResolverInterface $entryResolver,
        CallableExecutorInterface $callableExecutor,
    ): void {
        if ($this->entryResolver !== null || $this->callableExecutor !== null) {
            throw new LogicException('Container bootstrap state is already initialized.');
        }

        $this->entryResolver = $entryResolver;
        $this->callableExecutor = $callableExecutor;
    }

    public function entryResolver(): EntryResolverInterface
    {
        return $this->entryResolver
            ?? throw new LogicException('Container entry resolver is not initialized.');
    }

    public function callableExecutor(): CallableExecutorInterface
    {
        return $this->callableExecutor
            ?? throw new LogicException('Container callable executor is not initialized.');
    }
}
