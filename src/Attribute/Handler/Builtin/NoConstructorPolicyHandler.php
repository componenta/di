<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\ConstructorPolicyHandlerInterface;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\ResolutionContext;
use LogicException;
use ReflectionClass;

final readonly class NoConstructorPolicyHandler implements ConstructorPolicyHandlerInterface
{
    public function useConstructor(object $attribute, ReflectionClass $class, ResolutionContext $context): bool
    {
        if (!$attribute instanceof NoConstructor) {
            throw new LogicException('NoConstructorPolicyHandler received an unsupported attribute.');
        }

        return false;
    }
}
