<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use Reflector;
use Throwable;

use function Componenta\DI\normalize_env_name;

/** Handles #[Env] on parameters and properties. */
final class EnvHandler implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$attribute instanceof Env) {
            throw new LogicException('EnvHandler received an unsupported parameter attribute.');
        }
        if ($value->resolved) {
            return $value;
        }

        try {
            return ParameterAttributeValue::resolved($this->resolveEnv(
                envName: $attribute->name ?? normalize_env_name($target->name),
                typeName: self::typeName($target->type),
                hasDefault: $attribute->default !== DefaultValue::None,
                default: $attribute->default,
                declaringContext: $target->declaringContext,
            ));
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
            throw new LogicException('EnvHandler received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $context->writeProperty(
                $target,
                $this->resolveEnv(
                    envName: $attribute->name ?? normalize_env_name($target->getName()),
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

    private function environment(): Environment
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
