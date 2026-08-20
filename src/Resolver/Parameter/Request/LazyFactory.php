<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\FactoryInterface;
use Psr\Container\ContainerInterface;

final class LazyFactory implements FactoryInterface
{
    private ?FactoryInterface $factory = null;

    public function __construct(private readonly ContainerInterface $container) {}

    public function make(string $entry, array $params = []): object
    {
        if ($this->factory !== null) {
            return $this->factory->make($entry, $params);
        }

        $factory = $this->container->get(FactoryInterface::class);

        if (!$factory instanceof FactoryInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Service "%s" must implement %s; got %s.',
                FactoryInterface::class,
                FactoryInterface::class,
                get_debug_type($factory),
            ));
        }

        $this->factory = $factory;
        return $this->factory->make($entry, $params);
    }
}
