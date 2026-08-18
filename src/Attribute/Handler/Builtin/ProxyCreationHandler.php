<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\CreationStrategyHandlerInterface;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\ResolutionContext;
use LogicException;
use ReflectionClass;

final readonly class ProxyCreationHandler implements CreationStrategyHandlerInterface
{
    public function strategy(object $attribute, ReflectionClass $class, ResolutionContext $context): CreationStrategy
    {
        if (!$attribute instanceof Proxy) {
            throw new LogicException('ProxyCreationHandler received an unsupported attribute.');
        }

        return CreationStrategy::Proxy;
    }
}
