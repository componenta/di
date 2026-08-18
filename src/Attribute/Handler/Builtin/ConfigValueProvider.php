<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;
use Psr\Container\ContainerInterface;

final class ConfigValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    public function __construct(private readonly ContainerInterface $container) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof ConfigAttribute) {
            throw new LogicException('ConfigValueProvider received an unsupported attribute.');
        }

        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            throw new LogicException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
        }

        return $config->get($attribute->path ?? $target->name, $attribute->default);
    }
}
