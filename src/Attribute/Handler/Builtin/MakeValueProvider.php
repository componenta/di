<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Make;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;

/** @internal Transitional v5 handler; replaced by MakeAttributeResolver. */
final class MakeValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    public function __construct(private readonly FactoryInterface $factory) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof Make) {
            throw new LogicException('MakeValueProvider received an unsupported attribute.');
        }

        $entry = $attribute->entry ?? $target->className ?? $target->name;
        if ($entry === '') {
            throw new LogicException('#[Make] cannot infer a non-empty entry id.');
        }

        return $this->factory->make($entry, $attribute->params);
    }
}
