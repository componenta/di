<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;
use Psr\Container\ContainerInterface;

final class InjectValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    public function __construct(private readonly ContainerInterface $container) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof Inject) {
            throw new LogicException('InjectValueProvider received an unsupported attribute.');
        }

        $type = $target->className
            ?? throw new LogicException('#[Inject] requires a single class/interface target type.');

        return $this->container->get($type);
    }
}
