<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttr;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Compile\PlanPayloadValue;
use Componenta\DI\Compile\PropertyPlanResolverInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\PropertyTarget;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Resolves parameters and properties marked with the {@see Env} attribute by
 * reading the variable from the {@see Environment} attached to the container's
 * {@see Config}.
 *
 * Single class covers both injection targets: the {@see InjectionTargetInterface}
 * abstraction keeps the core logic (environment lookup, type casting, default
 * fallback) in one place, while the two interface methods format the result
 * into the tuple shape each aggregator expects.
 *
 * Supports typed conversion (`string`, `int`, `float`, `bool`, `array`) based
 * on the declared target type.
 *
 * @example Parameter usage
 * ```php
 * public function __construct(
 *     #[Env('DATABASE_HOST')] string $host,
 *     #[Env('DATABASE_PORT')] int $port,
 *     #[Env('APP_DEBUG')] bool $debug,
 * ) {}
 * ```
 *
 * @example Property usage
 * ```php
 * class Config {
 *     #[Env('DATABASE_HOST')] public string $host;
 * }
 * ```
 *
 * @example Implicit name conversion (snake/upper from the target name)
 * ```php
 * public function __construct(
 *     #[Env] string $databaseHost, // DATABASE_HOST
 * ) {}
 * ```
 *
 * @example With defaults
 * ```php
 * #[Env('REDIS_HOST', default: 'localhost')] string $host,
 * ```
 */
final readonly class EnvResolver implements
    ParameterResolverInterface,
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface,
    PropertyPlanResolverInterface
{
    public const string KIND = 'componenta.di.env';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        return $target->getAttributes(Env::class) !== [] ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        $attribute = $target->getAttributes(Env::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        /** @var Env $env */
        $env = $attribute->newInstance();

        if (!PlanPayloadValue::isCacheable($env->default)) {
            return null;
        }

        $hasDefault = $env->default !== DefaultValue::None;

        return [
            'name' => $env->name ?? EnvNameNormalizer::toEnvName($target->getName()),
            'type' => $this->typeName($target->getType()),
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $env->default : null,
        ];
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $target = new ParameterTarget($parameter);

        $env = $target->getFirstAttribute(Env::class);
        if ($env === null) {
            return null;
        }

        try {
            return [$parameter->getPosition(), $this->resolveEnvValue($env, $target)];
        } catch (ContainerExceptionInterface $e) {
            // Policy: surface container/DI exceptions unchanged - caller may
            // want to tell NotFound / Resolution / etc. apart.
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                previous: $e,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }
    }

    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $envPayload = $this->envPayload($payload);
        if ($envPayload === null) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        try {
            return [
                $parameter->getPosition(),
                $this->resolveEnvPayload($envPayload, $this->parameterContext($parameter)),
            ];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                previous: $e,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }
    }

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $target = new PropertyTarget($property);

        $env = $target->getFirstAttribute(Env::class);
        if ($env === null) {
            return null;
        }

        try {
            return [$property, $this->resolveEnvValue($env, $target)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    public function resolvePropertyPlan(
        ReflectionProperty $property,
        mixed $payload,
        array $context = [],
    ): ?array {
        $envPayload = $this->envPayload($payload);
        if ($envPayload === null) {
            return $this->resolveProperty($property, $context);
        }

        try {
            return [$property, $this->resolveEnvPayload($envPayload, $this->propertyContext($property))];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    /**
     * Resolves the environment variable referenced by the attribute, applying
     * type conversion based on the target's declared type and falling back to
     * the attribute's default value when possible.
     */
    private function resolveEnvValue(Env $env, InjectionTargetInterface $target): mixed
    {
        $envName = $env->name ?? EnvNameNormalizer::toEnvName($target->getName());

        return $this->resolveEnv(
            $envName,
            $this->typeName($target->getType()),
            $env->default !== DefaultValue::None,
            $env->default,
            $target->getDeclaringContext(),
        );
    }

    /**
     * @param array{name: string, type: string|null, hasDefault: bool, default: mixed} $payload
     */
    private function resolveEnvPayload(array $payload, string $declaringContext): mixed
    {
        return $this->resolveEnv(
            $payload['name'],
            $payload['type'],
            $payload['hasDefault'],
            $payload['default'],
            $declaringContext,
        );
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

    /**
     * Reads the environment variable with type coercion driven by the target's
     * declared type. Falls back to the raw string when the type is absent or
     * non-standard.
     */
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
            'int'    => $environment->int($envName),
            'float'  => $environment->float($envName),
            'bool'   => $environment->bool($envName),
            'array'  => $environment->array($envName),
            default  => $environment->get($envName),
        };
    }

    /**
     * Fetches the Environment from the container's Config, if available.
     */
    private function getEnvironment(): ?Environment
    {
        if (!$this->container->has(ConfigAttr::KEY)) {
            return null;
        }

        $config = $this->container->get(ConfigAttr::KEY);

        if (!$config instanceof Config) {
            return null;
        }

        return $config->environment;
    }

    private function typeName(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * @return array{name: string, type: string|null, hasDefault: bool, default: mixed}|null
     */
    private function envPayload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        if (
            !is_string($payload['name'] ?? null)
            || !array_key_exists('type', $payload)
            || (!is_string($payload['type'] ?? null) && ($payload['type'] ?? null) !== null)
            || !is_bool($payload['hasDefault'] ?? null)
            || !array_key_exists('default', $payload)
        ) {
            return null;
        }

        return $payload;
    }

    private function parameterContext(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();
        $class    = $parameter->getDeclaringClass();

        if ($class !== null) {
            return sprintf('%s::%s()', $class->getName(), $function->getName());
        }

        if ($function->isClosure()) {
            return 'Closure';
        }

        return sprintf('%s()', $function->getName());
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
