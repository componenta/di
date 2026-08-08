<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Resolves #[Env] parameters and handles #[Env] properties. */
final class EnvResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 400;
    }

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Env::class);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, Env::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof Env || !$target instanceof ReflectionProperty) {
            throw new LogicException('EnvResolver received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $value = $this->resolveEnv(
                envName: $attribute->name ?? EnvNameNormalizer::toEnvName($target->getName()),
                typeName: $this->typeName($target->getType()),
                hasDefault: $attribute->default !== DefaultValue::None,
                default: $attribute->default,
                declaringContext: $this->propertyContext($target),
            );
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }

        $context->writeProperty($target, $value);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $env = $target->firstAttribute(Env::class);
        if ($env === null) {
            return null;
        }

        try {
            return [
                $target->position,
                $this->resolveEnv(
                    envName: $env->name ?? EnvNameNormalizer::toEnvName($target->name),
                    typeName: $this->typeName($target->type),
                    hasDefault: $env->default !== DefaultValue::None,
                    default: $env->default,
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

    private function resolveEnv(
        string $envName,
        ?string $typeName,
        bool $hasDefault,
        mixed $default,
        string $declaringContext,
    ): mixed {
        $environment = $this->getEnvironment();

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

        return $this->getTypedValue($environment, $envName, $typeName);
    }

    private function getTypedValue(
        Environment $environment,
        string $envName,
        ?string $typeName,
    ): mixed {
        if ($typeName === null) {
            return $environment->get($envName);
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

    private function getEnvironment(): ?Environment
    {
        if (!$this->container->has(ConfigAttribute::KEY)) {
            return null;
        }

        $config = $this->container->get(ConfigAttribute::KEY);

        return $config instanceof Config ? $config->environment : null;
    }

    private function typeName(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private function propertyContext(ReflectionProperty $property): string
    {
        return sprintf(
            '%s::$%s',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
        );
    }
}
