<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\EnvNameNormalizer;
use Psr\Container\ContainerInterface;

/** Resolves #[Env] descriptors used as #[SetUp] parameter values. */
final readonly class EnvUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof Env;
    }

    public function unwrap(mixed $value, string $key): mixed
    {
        /** @var Env $value */
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config || $config->environment === null) {
            return $this->defaultOrFail($value, $key, 'environment is unavailable');
        }

        $name = $value->name ?? EnvNameNormalizer::toEnvName($key);
        if (!$config->environment->has($name)) {
            return $this->defaultOrFail(
                $value,
                $key,
                sprintf('environment variable "%s" is not defined', $name),
            );
        }

        return $config->environment->get($name);
    }

    private function defaultOrFail(Env $env, string $key, string $reason): mixed
    {
        if ($env->default !== DefaultValue::None) {
            return $env->default;
        }

        throw new ResolutionException(sprintf(
            'Cannot unwrap #[SetUp] param "%s" (#[Env]): %s.',
            $key,
            $reason,
        ));
    }
}
