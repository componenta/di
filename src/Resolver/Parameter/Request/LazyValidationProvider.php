<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;

final class LazyValidationProvider implements ValidationProviderInterface
{
    private bool $resolved = false;
    private ?ValidationProviderInterface $provider = null;

    public function __construct(private readonly ContainerInterface $container) {}

    public function provide(string $entryId): ?ValidatorInterface
    {
        return $this->provider()?->provide($entryId);
    }

    private function provider(): ?ValidationProviderInterface
    {
        if ($this->resolved) {
            return $this->provider;
        }
        if (!$this->container->has(ValidationProviderInterface::class)) {
            $this->resolved = true;
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
        $this->provider = $provider;
        $this->resolved = true;
        return $provider;
    }
}
