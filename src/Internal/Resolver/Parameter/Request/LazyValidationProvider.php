<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;

/** Mutable-container-aware validation provider bridge. @internal */
final readonly class LazyValidationProvider implements ValidationProviderInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function provide(string $entryId): ?ValidatorInterface
    {
        return $this->provider()?->provide($entryId);
    }

    private function provider(): ?ValidationProviderInterface
    {
        if (!$this->container->has(ValidationProviderInterface::class)) {
            return null;
        }
        $provider = $this->container->get(ValidationProviderInterface::class);
        if (!$provider instanceof ValidationProviderInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Service "%s" must implement %s; got %s.',
                ValidationProviderInterface::class,
                ValidationProviderInterface::class,
                get_debug_type($provider),
            ));
        }
        return $provider;
    }
}
