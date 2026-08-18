<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;
use Psr\Container\ContainerInterface;

/** Implicit container lookup by the target's single class/interface type. */
final readonly class AutowireValueFallback implements ValueFallbackInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function supports(ValueTargetInterface $target): bool
    {
        return $target->className !== null;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        $type = $target->className;

        return $type !== null && $this->container->has($type)
            ? new ValueResult($this->container->get($type))
            : null;
    }
}
