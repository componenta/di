<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\Caster\CasterExceptionInterface;
use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;
use Componenta\Validation\Context;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Exception\ValidationExceptionInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Reflector;
use Throwable;

/** Handles all request-source attributes on parameters. */
final class RequestAttributeHandler implements ParameterAttributeHandlerInterface
{
    public const string PARAMETER_NAME_ATTRIBUTE = '__parameter_name';

    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly CasterProviderInterface $casterProvider,
        private readonly ?ValidationProviderInterface $validationProvider = null,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed {
        try {
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

            return $this->extractValue($request, $attribute, $target->reflection);
        } catch (ValidationExceptionInterface|CasterExceptionInterface|ContainerExceptionInterface|InvalidArgumentException $e) {
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
        throw new LogicException(sprintf(
            '%s is a parameter-only attribute handler.',
            self::class,
        ));
    }

    private function extractValue(
        ServerRequestInterface $request,
        object $attribute,
        ReflectionParameter $parameter,
    ): mixed {
        if ($attribute instanceof RequestDataExtractorInterface && $attribute instanceof MapperInterface) {
            if ($attribute instanceof CasterProviderAwareInterface) {
                $attribute->provider = $this->casterProvider;
            }
            return $this->processMapping($request, $attribute, $parameter);
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

    private function attributeShortName(object $attribute): string
    {
        $class = $attribute::class;
        $position = strrpos($class, '\\');
        return $position === false ? $class : substr($class, $position + 1);
    }

    /** @return array<string|int,mixed>|object */
    private function processMapping(
        ServerRequestInterface $request,
        RequestDataExtractorInterface&MapperInterface $mapper,
        ReflectionParameter $parameter,
    ): array|object {
        $typeName = $this->resolveTypeName($parameter);
        $rawData = $mapper->extract($request);
        $this->assertNamedDtoData($typeName, $rawData, $parameter);
        $this->validateData($typeName, $rawData);
        $data = $mapper->transform($rawData);
        $this->assertNamedDtoData($typeName, $data, $parameter);

        if ($typeName === null) {
            return $data;
        }

        MappedRequestParameterSourceGuard::assertNoConflicts($typeName, $data);
        $params = RequestParameter::with($data, $request);
        $params = MappedRequestContext::with($params, $data);
        return $this->factory->make($typeName, $params);
    }

    /** @return class-string|null */
    private function resolveTypeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if ($type === null || self::containsBuiltinType($type, 'array')) {
            return null;
        }

        $classTypes = TypeHints::classNames($type, $parameter->getDeclaringClass());
        if (count($classTypes) > 1) {
            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf(
                    'request DTO mapping requires exactly one class type; got %s',
                    implode('|', $classTypes),
                ),
            );
        }
        return $classTypes[0] ?? null;
    }

    private static function containsBuiltinType(ReflectionType $type, string $name): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() && $type->getName() === $name;
        }
        if (!$type instanceof ReflectionUnionType && !$type instanceof ReflectionIntersectionType) {
            return false;
        }
        foreach ($type->getTypes() as $nested) {
            if (self::containsBuiltinType($nested, $name)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string|int,mixed> $data */
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

    /** @param array<string|int,mixed> $data */
    private function assertNamedDtoData(
        ?string $typeName,
        array $data,
        ReflectionParameter $parameter,
    ): void {
        if ($typeName === null) {
            return;
        }
        foreach ($data as $key => $_value) {
            if (!is_string($key)) {
                throw ResolutionException::forParameter(
                    $parameter,
                    reason: 'HTTP DTO mapping accepts only named string keys',
                );
            }
        }
    }
}
