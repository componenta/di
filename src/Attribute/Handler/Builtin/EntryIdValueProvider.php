<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;
use Psr\Container\ContainerInterface;

final class EntryIdValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    public function __construct(private readonly ContainerInterface $container) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof EntryId) {
            throw new LogicException('EntryIdValueProvider received an unsupported attribute.');
        }

        return $this->container->get($attribute->value);
    }
}
