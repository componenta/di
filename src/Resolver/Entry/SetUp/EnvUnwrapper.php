<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionParameter;

use function Componenta\DI\normalize_env_name;

/** Resolves #[Env] descriptors used as #[SetUp] parameter values. */
final readonly class EnvUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof Env;
    }

    public function unwrap(
        mixed $value,
        string $key,
        ?ReflectionParameter $parameter = null,
    ): mixed {
        /** @var Env $value */
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            return $this->defaultOrFail($value, $key, 'configuration is unavailable');
        }

        $name = $value->name ?? normalize_env_name($key);
        if (!$config->environment->has($name)) {
            return $this->defaultOrFail(
                $value,
                $key,
                sprintf('environment variable "%s" is not defined', $name),
            );
        }

        return self::read($config->environment, $name, $parameter);
    }

    private static function read(
        Environment $environment,
        string $name,
        ?ReflectionParameter $parameter,
    ): mixed {
        $type = $parameter?->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        return match ($typeName) {
            'string' => $environment->string($name),
            'int' => $environment->int($name),
            'float' => $environment->float($name),
            'bool' => $environment->bool($name),
            'array' => $environment->array($name),
            default => $environment->get($name),
        };
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
