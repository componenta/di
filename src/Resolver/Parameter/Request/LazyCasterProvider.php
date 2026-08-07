<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Psr\Container\ContainerInterface;

/** Defers resolving the caster registry until a request attribute uses casting. */
final class LazyCasterProvider implements CasterProviderInterface
{
    private ?CasterProviderInterface $provider = null;

    public function __construct(private readonly ContainerInterface $container) {}

    public function provide(string $name): ?CasterInterface
    {
        return $this->provider()->provide($name);
    }

    private function provider(): CasterProviderInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $provider = $this->container->get(CasterProviderInterface::class);
        if (!$provider instanceof CasterProviderInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Service "%s" must implement %s; got %s.',
                CasterProviderInterface::class,
                CasterProviderInterface::class,
                get_debug_type($provider),
            ));
        }

        return $this->provider = $provider;
    }
}
