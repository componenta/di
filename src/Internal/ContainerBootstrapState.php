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
    private ?EntryResolverInterface $resolver = null;

    private ?CallableExecutorInterface $executor = null;

    public EntryResolverInterface $entryResolver {
        get => $this->resolver ?? throw new LogicException('Container entry resolver is not initialized.');
    }

    public CallableExecutorInterface $callableExecutor {
        get => $this->executor ?? throw new LogicException('Container callable executor is not initialized.');
    }

    public function initialize(
        EntryResolverInterface $entryResolver,
        CallableExecutorInterface $callableExecutor,
    ): void {
        if ($this->resolver !== null || $this->executor !== null) {
            throw new LogicException('Container bootstrap state is already initialized.');
        }

        $this->resolver = $entryResolver;
        $this->executor = $callableExecutor;
    }
}
