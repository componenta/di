<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\Caster\CasterExceptionInterface;
use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\Reflection\ReflectionType;
use Componenta\Validation\Context;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Exception\ValidationExceptionInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use ReflectionParameter;

/** Resolves parameters from a PSR-7 request. */
final class RequestResolver implements ParameterResolverInterface
{
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
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        foreach ($target->attributeClasses as $attributeClass) {
            if ($this->isRequestAttribute($attributeClass)) {
                return true;
            }
        }

        return $target->type !== null
            && ReflectionType::contains($target->type, UriInterface::class);
    }

    /** @throws ValidationExceptionInterface|CasterExceptionInterface */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $this->getAttribute($target);

        if ($attribute === null) {
            return $this->resolveByType($target, $context);
        }

        $request = RequestParameter::get($context->provided);

        if ($request === null) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf(
                    'PSR-7 request is required for #[%s]',
                    $this->attributeShortName($attribute),
                ),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        return [
            $target->position,
            $this->extractValue($request, $attribute, $target->reflection),
        ];
    }

    /** @throws ValidationExceptionInterface|CasterExceptionInterface */
    private function extractValue(
        ServerRequestInterface $request,
        object $attribute,
        ReflectionParameter $parameter,
    ): mixed {
        if ($attribute instanceof RequestDataExtractorInterface
            && $attribute instanceof MapperInterface
        ) {
            if ($attribute instanceof CasterProviderAwareInterface) {
                $attribute->provider = $this->casterProvider;
            }

            return $this->processMapping(
                $request,
                $attribute,
                $parameter,
            );
        }

        if ($attribute instanceof ExtractorInterface) {
            $value = $attribute->extract(
                $request->withAttribute(self::PARAMETER_NAME_ATTRIBUTE, $parameter->getName()),
            );

            if ($attribute instanceof CastableInterface && $attribute->cast !== null) {
                $caster = $this->casterProvider->provide($attribute->cast);

                if ($caster === null) {
                    throw ResolutionException::forParameter(
                        $parameter,
                        reason: sprintf('caster "%s" is not registered', $attribute->cast),
                    );
                }

                $value = $caster->cast($value);
            }

            return $value;
        }

        throw ResolutionException::forParameter(
            $parameter,
            reason: sprintf(
                'request attribute "%s" must implement %s + %s or %s',
                $attribute::class,
                RequestDataExtractorInterface::class,
                MapperInterface::class,
                ExtractorInterface::class,
            ),
        );
    }

    private function resolveByType(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $target->type !== null
            && ReflectionType::contains($target->type, UriInterface::class)
            ? $this->resolveUri($target, $context)
            : null;
    }

    /** @return array{0: int, 1: UriInterface} */
    private function resolveUri(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        $request = RequestParameter::get($context->provided);

        if ($request === null) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf(
                    'PSR-7 request is required to resolve type "%s"',
                    UriInterface::class,
                ),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        return [$target->position, $request->getUri()];
    }

    private function getAttribute(ParameterTarget $target): ?object
    {
        foreach ($target->attributeClasses as $attributeClass) {
            if ($this->isRequestAttribute($attributeClass)) {
                return $target->firstAttribute($attributeClass);
            }
        }

        return null;
    }

    private function isRequestAttribute(string $class): bool
    {
        return self::$inheritanceCache[$class] ??= (
            is_a($class, RequestDataExtractorInterface::class, true)
            && is_a($class, MapperInterface::class, true)
        ) || is_a($class, ExtractorInterface::class, true);
    }

    private function attributeShortName(object $attribute): string
    {
        $class = $attribute::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /** @throws ValidationExceptionInterface */
    private function processMapping(
        ServerRequestInterface $request,
        RequestDataExtractorInterface&MapperInterface $mapper,
        ReflectionParameter $parameter,
    ): array|object {
        $typeName = $this->resolveTypeName($parameter);
        $rawData = $mapper->extract($request);
        $this->validateData($typeName, $rawData);
        $data = $mapper->transform($rawData);

        return $typeName !== null
            ? $this->factory->make($typeName, $data)
            : $data;
    }

    private function resolveTypeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (ReflectionType::contains($type, 'array')) {
            return null;
        }

        return array_find(
            ReflectionType::getTypeNames($type),
            static fn(string $typeName): bool => !isset(self::BUILTIN_TYPES[$typeName]),
        );
    }

    /** @throws ValidationExceptionInterface */
    private function validateData(?string $typeName, array $data): void
    {
        if ($typeName === null || $this->validationProvider === null) {
            return;
        }

        $this->validationProvider->provide($typeName)?->validate(
            $data,
            new Context([ContextInterface::THROW_ON_FAILURE_ATTRIBUTE => true]),
        );
    }

}
