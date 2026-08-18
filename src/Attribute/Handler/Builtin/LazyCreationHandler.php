<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\CreationStrategyHandlerInterface;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\ResolutionContext;
use LogicException;
use ReflectionClass;

final readonly class LazyCreationHandler implements CreationStrategyHandlerInterface
{
    public function strategy(object $attribute, ReflectionClass $class, ResolutionContext $context): CreationStrategy
    {
        if (!$attribute instanceof Lazy) {
            throw new LogicException('LazyCreationHandler received an unsupported attribute.');
        }

        return CreationStrategy::Lazy;
    }
}
