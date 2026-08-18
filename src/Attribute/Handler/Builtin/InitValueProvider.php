<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Init;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;

final readonly class InitValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    public function __construct(private CallableExecutorInterface $executor) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof Init) {
            throw new LogicException('InitValueProvider received an unsupported attribute.');
        }

        return $this->executor->execute(
            $attribute->callable,
            new ResolutionContext(
                explicit: $attribute->params,
                trusted: $context->resolution->trusted,
            ),
        );
    }
}
