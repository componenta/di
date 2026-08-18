<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Resolver\EnvNameNormalizer;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;
use Psr\Container\ContainerInterface;

final class EnvValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    public function __construct(private readonly ContainerInterface $container) {}

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof Env) {
            throw new LogicException('EnvValueProvider received an unsupported attribute.');
        }

        $config = $this->container->get(Config::class);
        if (!$config instanceof Config || $config->environment === null) {
            throw new LogicException('Environment is unavailable in the application Config.');
        }

        return $config->environment->get(
            $attribute->name ?? EnvNameNormalizer::toEnvName($target->name),
            $attribute->default,
        );
    }
}
