<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\EnvNameNormalizer;
use Psr\Container\ContainerInterface;

/**
 * Unwraps {@see Env} value-objects inside SetUp params by reading the variable
 * from the {@see Environment} attached to the container's {@see Config}.
 *
 * Falls back to {@see Env::$default} when the environment is unavailable or
 * the variable is undefined. Throws a container-typed exception if neither
 * the environment nor a default is available.
 */
final readonly class EnvUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof Env;
    }

    public function unwrap(mixed $value, string $key): mixed
    {
        /** @var Env $value */
        $environment = $this->getEnvironment();

        if ($environment === null) {
            return $this->defaultOrFail(
                $value,
                $key,
                'environment is not available in Config',
            );
        }

        $envName = $value->name ?? EnvNameNormalizer::toEnvName($key);

        if (!$environment->has($envName)) {
            return $this->defaultOrFail(
                $value,
                $key,
                sprintf('environment variable "%s" is not defined', $envName),
            );
        }

        return $environment->get($envName);
    }

    /**
     * Safely resolves the Environment instance from Config. Returns null when
     * Config is not registered or doesn't carry an Environment - callers fall
     * back to the attribute's default.
     */
    private function getEnvironment(): ?Environment
    {
        if (!$this->container->has(Config::class)) {
            return null;
        }

        $config = $this->container->get(Config::class);

        if (!$config instanceof Config) {
            return null;
        }

        return $config->environment;
    }

    private function defaultOrFail(Env $env, string $key, string $reason): mixed
    {
        if ($env->default !== DefaultValue::None) {
            return $env->default;
        }

        throw new ResolutionException(
            sprintf(
                'Cannot unwrap #[SetUp] param "%s" (#[Env] attribute): %s.',
                $key,
                $reason,
            ),
        );
    }
}
