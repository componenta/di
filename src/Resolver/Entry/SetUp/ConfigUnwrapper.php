<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Exception\ResolutionException;
use Psr\Container\ContainerInterface;
use Throwable;

/** Resolves #[Config] descriptors used as #[SetUp] parameter values. */
final readonly class ConfigUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof ConfigAttribute;
    }

    public function unwrap(mixed $value, string $key): mixed
    {
        /** @var ConfigAttribute $value */
        try {
            $config = $this->container->get(Config::class);
            if (!$config instanceof Config) {
                throw new \LogicException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
            }

            return $config->get($value->path ?? $key, $value->default);
        } catch (Throwable $e) {
            throw new ResolutionException(sprintf(
                'Cannot unwrap #[SetUp] param "%s" (#[Config]): %s',
                $key,
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
