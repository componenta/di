<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Psr\Container\ContainerInterface;

/** Mutable-container-aware caster provider bridge. @internal */
final readonly class LazyCasterProvider implements CasterProviderInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function provide(string $name): ?CasterInterface
    {
        return $this->provider()->provide($name);
    }

    private function provider(): CasterProviderInterface
    {
        $provider = $this->container->get(CasterProviderInterface::class);
        if (!$provider instanceof CasterProviderInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Service "%s" must implement %s; got %s.',
                CasterProviderInterface::class,
                CasterProviderInterface::class,
                get_debug_type($provider),
            ));
        }
        return $provider;
    }
}
