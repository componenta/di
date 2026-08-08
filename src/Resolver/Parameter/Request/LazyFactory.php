<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\FactoryInterface;
use Psr\Container\ContainerInterface;

/** Defers resolving the object factory until request mapping actually needs it. */
final class LazyFactory implements FactoryInterface
{
    private ?FactoryInterface $factory = null;

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @template T of object
     *
     * @param class-string<T>|string $entry
     * @param array<string|int, mixed> $params
     *
     * @return T
     */
    public function make(string $entry, array $params = []): object
    {
        return $this->factory()->make($entry, $params);
    }

    private function factory(): FactoryInterface
    {
        if ($this->factory !== null) {
            return $this->factory;
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

        return $this->factory = $factory;
    }
}
