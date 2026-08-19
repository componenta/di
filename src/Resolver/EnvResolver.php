<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use Reflector;
use Throwable;

/** Resolves #[Env] parameters and handles #[Env] properties. */
final class EnvResolver implements ParameterResolverInterface, AttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Env::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(Env::class);
        if (!$attribute instanceof Env) {
            return null;
        }

        try {
            return [
                $target->position,
                $this->resolveEnv(
                    envName: $attribute->name ?? EnvNameNormalizer::toEnvName($target->name),
                    typeName: self::typeName($target->type),
                    hasDefault: $attribute->default !== DefaultValue::None,
                    default: $attribute->default,
                    declaringContext: $target->declaringContext,
                ),
            ];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                previous: $e,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof Env || !$target instanceof ReflectionProperty) {
            throw new LogicException('EnvResolver received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $context->writeProperty(
                $target,
                $this->resolveEnv(
                    envName: $attribute->name ?? EnvNameNormalizer::toEnvName($target->getName()),
                    typeName: self::typeName($target->getType()),
                    hasDefault: $attribute->default !== DefaultValue::None,
                    default: $attribute->default,
                    declaringContext: sprintf(
                        '%s::$%s',
                        $target->getDeclaringClass()->getName(),
                        $target->getName(),
                    ),
                ),
            );
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }

    private function resolveEnv(
        string $envName,
        ?string $typeName,
        bool $hasDefault,
        mixed $default,
        string $declaringContext,
    ): mixed {
        $environment = $this->environment();
        if ($environment === null) {
            if ($hasDefault) {
                return $default;
            }

            throw new ResolutionException(sprintf(
                'Environment is not available in Config while resolving %s.',
                $declaringContext,
            ));
        }

        if (!$environment->has($envName)) {
            if ($hasDefault) {
                return $default;
            }

            throw new ResolutionException(sprintf(
                'Environment variable "%s" is not defined (required by %s).',
                $envName,
                $declaringContext,
            ));
        }

        return match ($typeName) {
            'string' => $environment->string($envName),
            'int' => $environment->int($envName),
            'float' => $environment->float($envName),
            'bool' => $environment->bool($envName),
            'array' => $environment->array($envName),
            default => $environment->get($envName),
        };
    }

    private function environment(): ?Environment
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            throw new LogicException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
        }

        return $config->environment;
    }

    private static function typeName(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
