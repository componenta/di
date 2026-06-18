<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\Caster\CasterExceptionInterface;
use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\Reflection\ReflectionType;
use Componenta\Validation\Context;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Exception\ValidationExceptionInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Resolves parameters from PSR-7 HTTP request.
 */
final class RequestResolver implements
    ParameterResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface
{
    public const string KIND = 'componenta.di.request';

    private const string PAYLOAD_ATTRIBUTE = 'attribute';
    private const string PAYLOAD_URI = 'uri';

    /**
     * Request attribute name for storing parameter name.
     * Used by ExtractorInterface implementations to access parameter name when needed.
     */
    public const string PARAMETER_NAME_ATTRIBUTE = '__parameter_name';

    /** @var array<string, true> */
    private const array BUILTIN_TYPES = [
        'array' => true,
        'bool' => true,
        'callable' => true,
        'false' => true,
        'float' => true,
        'int' => true,
        'iterable' => true,
        'mixed' => true,
        'never' => true,
        'null' => true,
        'object' => true,
        'string' => true,
        'true' => true,
        'void' => true,
    ];

    /** @var array<class-string, bool> */
    private static array $inheritanceCache = [];

    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly CasterProviderInterface $casterProvider,
        private readonly ?ValidationProviderInterface $validationProvider = null,
    ) {
    }

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        if (!$target instanceof ReflectionParameter) {
            return null;
        }

        // Any attribute that the dispatch path understands (request-data mapper or single-value extractor).
        foreach ($target->getAttributes() as $attr) {
            $name = $attr->getName();
            if ((is_a($name, RequestDataExtractorInterface::class, true)
                    && is_a($name, MapperInterface::class, true))
                || is_a($name, ExtractorInterface::class, true)
            ) {
                return self::KIND;
            }
        }

        // Type-based fallback for UriInterface.
        $type = $target->getType();
        return $type !== null && ReflectionType::contains($type, UriInterface::class)
            ? self::KIND
            : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        if (!$target instanceof ReflectionParameter) {
            return null;
        }

        foreach ($target->getAttributes() as $attr) {
            $name = $attr->getName();
            if ($this->isInheritedAttribute($name)) {
                return [
                    'mode' => self::PAYLOAD_ATTRIBUTE,
                    'attribute' => $name,
                    'targetType' => $this->resolveTypeName($target),
                ];
            }
        }

        $type = $target->getType();
        if ($type !== null && ReflectionType::contains($type, UriInterface::class)) {
            return ['mode' => self::PAYLOAD_URI];
        }

        return null;
    }

    /**
     * @throws ValidationExceptionInterface
     * @throws CasterExceptionInterface
     */
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {

        $attr = $this->getAttribute($parameter);

        if ($attr === null) {
            return $this->resolveByType($parameter, $providedParameters, $resolvedParameters);
        }

        $request = RequestParameter::get($providedParameters);

        if ($request === null) {
            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf(
                    'PSR-7 request is required for #[%s]',
                    $this->getAttributeShortName($attr),
                ),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        $result = $this->extractValue($request, $attr, $parameter);

        return [$parameter->getPosition(), $result];
    }

    /**
     * @throws ValidationExceptionInterface
     * @throws CasterExceptionInterface
     */
    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $requestPayload = $this->requestPayload($payload);
        if ($requestPayload === null) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        if ($requestPayload['mode'] === self::PAYLOAD_URI) {
            return $this->resolveUri($parameter, $providedParameters, $resolvedParameters);
        }

        $attributeClass = $requestPayload['attribute'] ?? null;
        if (!is_string($attributeClass)) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        $attr = $this->getAttributeByClass($parameter, $attributeClass);
        if ($attr === null) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        $request = RequestParameter::get($providedParameters);

        if ($request === null) {
            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf(
                    'PSR-7 request is required for #[%s]',
                    $this->getAttributeShortName($attr),
                ),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        $result = $this->extractValue(
            $request,
            $attr,
            $parameter,
            $requestPayload['targetType'],
            true,
        );

        return [$parameter->getPosition(), $result];
    }

    /**
     * @throws ValidationExceptionInterface
     * @throws CasterExceptionInterface
     * @throws ResolutionException If the attribute implements neither a
     *                               request-data extractor+mapper pair nor
     *                               {@see ExtractorInterface}.
     */
    private function extractValue(
        ServerRequestInterface $request,
        object $attr,
        ReflectionParameter $parameter,
        ?string $compiledTargetType = null,
        bool $hasCompiledTargetType = false,
    ): mixed {
        // For Map* attributes combining request-data extraction and transformation.
        if ($attr instanceof RequestDataExtractorInterface && $attr instanceof MapperInterface) {

            if ($attr instanceof CasterProviderAwareInterface) {
                $attr->provider = $this->casterProvider;
            }

            return $this->processMapping(
                $request,
                $attr,
                $parameter,
                $compiledTargetType,
                $hasCompiledTargetType,
            );
        }

        // For single-value attributes implementing ExtractorInterface
        if ($attr instanceof ExtractorInterface) {
            // Pass parameter name via request attribute
            $requestWithParam = $request->withAttribute(self::PARAMETER_NAME_ATTRIBUTE, $parameter->getName());
            $value = $attr->extract($requestWithParam);

            // Apply casting if attribute implements CastableInterface
            if ($attr instanceof CastableInterface && $attr->cast !== null) {
                $caster = $this->casterProvider->provide($attr->cast);

                if ($caster === null) {
                    throw ResolutionException::forParameter(
                        $parameter,
                        reason: sprintf('caster "%s" is not registered', $attr->cast),
                    );
                }

                $value = $caster->cast($value);
            }

            return $value;
        }

        // Defensive: getAttribute() must filter to request-data or extractor attributes,
        // but if the inheritance cache ever lets a foreign attribute through,
        // surface it instead of silently returning null.
        throw ResolutionException::forParameter(
            $parameter,
            reason: sprintf(
                'request attribute "%s" must implement %s + %s or %s',
                $attr::class,
                RequestDataExtractorInterface::class,
                MapperInterface::class,
                ExtractorInterface::class,
            ),
        );
    }


    private function resolveByType(
        ReflectionParameter $parameter,
        array $providedParameters,
        array $resolvedParameters,
    ): ?array {
        $type = $parameter->getType();

        if ($type === null || !ReflectionType::contains($type, UriInterface::class)) {
            return null;
        }

        return $this->resolveUri($parameter, $providedParameters, $resolvedParameters);
    }

    private function resolveUri(
        ReflectionParameter $parameter,
        array $providedParameters,
        array $resolvedParameters,
    ): array {
        $request = RequestParameter::get($providedParameters);

        if ($request === null) {
            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf('PSR-7 request is required to resolve type "%s"', UriInterface::class),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        return [$parameter->getPosition(), $request->getUri()];
    }

    private function getAttribute(ReflectionParameter $parameter): ?object
    {
        foreach ($parameter->getAttributes() as $attribute) {
            $name = $attribute->getName();

            if ($this->isInheritedAttribute($name)) {
                return $attribute->newInstance();
            }
        }

        return null;
    }

    private function getAttributeByClass(ReflectionParameter $parameter, string $className): ?object
    {
        $attribute = $parameter->getAttributes($className)[0] ?? null;

        return $attribute?->newInstance();
    }

    private function isInheritedAttribute(string $className): bool
    {
        if (isset(self::$inheritanceCache[$className])) {
            return self::$inheritanceCache[$className];
        }

        // Check if class implements a request-data mapper pair or single-value extractor.
        $result = (is_a($className, RequestDataExtractorInterface::class, true)
                && is_a($className, MapperInterface::class, true))
            || is_a($className, ExtractorInterface::class, true);

        return self::$inheritanceCache[$className] = $result;
    }

    private function getAttributeShortName(object $attr): string
    {
        $class = $attr::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * @throws ValidationExceptionInterface
     */
    private function processMapping(
        ServerRequestInterface $request,
        RequestDataExtractorInterface&MapperInterface $mapper,
        ReflectionParameter $parameter,
        ?string $compiledTargetType = null,
        bool $hasCompiledTargetType = false,
    ): array|object {

        // STEP 1: Determine target type (DTO or array)
        $typeName = $hasCompiledTargetType
            ? $compiledTargetType
            : $this->resolveTypeName($parameter);

        // STEP 2: Extract raw request data.
        $rawData = $mapper->extract($request);

        // STEP 3: Validate the request contract before any casting or domain lookup.
        $this->validateData($typeName, $rawData);

        // STEP 4: Transform valid input into DTO constructor data.
        $data = $mapper->transform($rawData);

        // STEP 5: Hydrate DTO or return array
        return $typeName !== null
            ? $this->factory->make($typeName, $data)
            : $data;
    }

    /**
     * Resolves the class/interface type name from a parameter, skipping built-in types.
     */
    private function resolveTypeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (ReflectionType::contains($type, 'array')) {
            return null;
        }

        return array_find(ReflectionType::getTypeNames($type), static fn($typeName) => !isset(self::BUILTIN_TYPES[$typeName]));
    }

    /**
     * @throws ValidationExceptionInterface
     */
    private function validateData(?string $typeName, array $data): void
    {
        if ($typeName === null || $this->validationProvider === null) {
            return;
        }

        $this->validationProvider->provide($typeName)?->validate(
            $data,
            new Context([ContextInterface::THROW_ON_FAILURE_ATTRIBUTE => true])
        );
    }

    /**
     * @return array{mode: string, attribute?: string, targetType?: string|null}|null
     */
    private function requestPayload(mixed $payload): ?array
    {
        if (!is_array($payload) || !is_string($payload['mode'] ?? null)) {
            return null;
        }

        if ($payload['mode'] === self::PAYLOAD_URI) {
            return ['mode' => self::PAYLOAD_URI];
        }

        if (
            $payload['mode'] !== self::PAYLOAD_ATTRIBUTE
            || !is_string($payload['attribute'] ?? null)
            || !array_key_exists('targetType', $payload)
            || (!is_string($payload['targetType'] ?? null) && ($payload['targetType'] ?? null) !== null)
        ) {
            return null;
        }

        return $payload;
    }
}
