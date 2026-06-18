<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Config\DefaultValue;
use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config;
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
use Psr\Container\NotFoundExceptionInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Resolves parameters and properties marked with {@see Config} by reading
 * values from the container's configuration entry (service id {@see Config::KEY}).
 *
 * Lookup logic lives in {@see ConfigValueExtractor}; this class only adapts
 * the resolver protocol (target -> tuple) and translates extractor errors into
 * {@see ResolutionException}.
 *
 * @example Literal key (parameter)
 * ```php
 * public function __construct(#[Config('database_host')] string $host) {}
 * ```
 *
 * @example Nested path (property)
 * ```php
 * class Service {
 *     #[Config(new ConfigPath('database.host'))] private string $dbHost;
 * }
 * ```
 */
final readonly class ConfigAttributeResolver implements
    ParameterResolverInterface,
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface,
    PropertyPlanResolverInterface
{
    public const string KIND = 'componenta.di.config';

    private ConfigValueExtractor $extractor;

    public function __construct(
        private ContainerInterface $container,
        ?ConfigValueExtractor $extractor = null,
    ) {
        $this->extractor = $extractor ?? new ConfigValueExtractor();
    }

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        return $target->getAttributes(Config::class) !== [] ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        $attribute = $target->getAttributes(Config::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        /** @var Config $config */
        $config = $attribute->newInstance();

        if (!PlanPayloadValue::isCacheable($config->default)) {
            return null;
        }

        $hasDefault = $config->default !== DefaultValue::None;

        if ($config->path instanceof ConfigPath) {
            return [
                'mode' => ConfigValueExtractor::MODE_PATH,
                'key' => $config->path->value,
                'segments' => $config->path->toArray(),
                'hasDefault' => $hasDefault,
                'default' => $hasDefault ? $config->default : null,
            ];
        }

        return [
            'mode' => $config->path === null
                ? ConfigValueExtractor::MODE_IMPLICIT
                : ConfigValueExtractor::MODE_LITERAL,
            'key' => $config->path,
            'segments' => [],
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $config->default : null,
        ];
    }

    /**
     * @throws ContainerExceptionInterface
     */
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $target = new ParameterTarget($parameter);
        $config = $target->getFirstAttribute(Config::class);

        if ($config === null) {
            return null;
        }

        try {
            return [$parameter->getPosition(), $this->readFromConfig($config, $target->getName())];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
                previous: $e,
            );
        }
    }

    /**
     * @throws ContainerExceptionInterface
     */
    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $configPayload = $this->configPayload($payload);
        if ($configPayload === null) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        try {
            return [
                $parameter->getPosition(),
                $this->readFromConfigPayload($configPayload, $parameter->getName()),
            ];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
                previous: $e,
            );
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $target = new PropertyTarget($property);
        $config = $target->getFirstAttribute(Config::class);

        if ($config === null) {
            return null;
        }

        try {
            return [$property, $this->readFromConfig($config, $target->getName())];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function resolvePropertyPlan(
        ReflectionProperty $property,
        mixed $payload,
        array $context = [],
    ): ?array {
        $configPayload = $this->configPayload($payload);
        if ($configPayload === null) {
            return $this->resolveProperty($property, $context);
        }

        try {
            return [
                $property,
                $this->readFromConfigPayload($configPayload, $property->getName()),
            ];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function readFromConfig(Config $config, string $fallbackName): mixed
    {
        $configData = $this->container->get(Config::KEY);

        return $this->extractor->extract($configData, $config, $fallbackName);
    }

    /**
     * @param array{mode: string, key: string|null, segments: list<string>, hasDefault: bool, default: mixed} $payload
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function readFromConfigPayload(array $payload, string $fallbackName): mixed
    {
        $configData = $this->container->get(Config::KEY);

        return $this->extractor->extractCompiled(
            $configData,
            $payload['mode'],
            $payload['key'],
            $payload['segments'],
            $payload['hasDefault'] ? $payload['default'] : DefaultValue::None,
            $fallbackName,
        );
    }

    /**
     * @return array{mode: string, key: string|null, segments: list<string>, hasDefault: bool, default: mixed}|null
     */
    private function configPayload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        if (
            !is_string($payload['mode'] ?? null)
            || !array_key_exists('key', $payload)
            || (!is_string($payload['key']) && $payload['key'] !== null)
            || !is_array($payload['segments'] ?? null)
            || !is_bool($payload['hasDefault'] ?? null)
            || !array_key_exists('default', $payload)
        ) {
            return null;
        }

        foreach ($payload['segments'] as $segment) {
            if (!is_string($segment)) {
                return null;
            }
        }

        return $payload;
    }
}
