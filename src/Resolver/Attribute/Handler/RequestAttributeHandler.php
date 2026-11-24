<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Closure;
use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ConstructionParameters;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextAccess;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestPublication;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestResultCapture;
use Componenta\DI\Internal\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\CallbackMapperInterface;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\ParameterNameAwareExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\Validation\Context;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/** Handles all request-source attributes on parameters. */
final readonly class RequestAttributeHandler implements ParameterAttributeHandlerInterface
{
    /** @param (Closure(Closure,array<string|int,mixed>):mixed)|null $callbacks */
    public function __construct(
        private FactoryInterface $factory,
        private CasterProviderInterface $casterProvider,
        private ?ValidationProviderInterface $validationProvider = null,
        private ?Closure $callbacks = null,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if ($value->resolved) {
            return $value;
        }

        $request = RequestParameter::get(ParameterContextAccess::provided($context));
        if ($request === null) {
            throw ParameterContextAccess::failure(
                $context,
                $target->reflection,
                reason: sprintf(
                    'PSR-7 request is required for #[%s]',
                    $this->attributeShortName($attribute),
                ),
            );
        }

        $sourceChecks = [];
        $result = $this->extractValue($request, $attribute, $target, $sourceChecks);
        if ($sourceChecks !== [] && is_object($result)) {
            MappedRequestResultCapture::record($result, $sourceChecks);
        }
        return ParameterAttributeValue::resolved($result);
    }

    /**
     * @param list<Closure():void> $sourceChecks
     * @param-out list<Closure():void> $sourceChecks
     */
    private function extractValue(
        ServerRequestInterface $request,
        object $attribute,
        ParameterTarget $target,
        array &$sourceChecks,
    ): mixed {
        if ($attribute instanceof RequestDataExtractorInterface && $attribute instanceof MapperInterface) {
            return $this->processMapping($request, $attribute, $target, $sourceChecks);
        }

        if ($attribute instanceof ExtractorInterface) {
            $value = $attribute instanceof ParameterNameAwareExtractorInterface
                ? $attribute->extractForParameter($request, $target->name)
                : $attribute->extract($request);

            if ($attribute instanceof CastableInterface && $attribute->cast !== null) {
                $caster = $this->casterProvider->provide($attribute->cast);
                if ($caster === null) {
                    throw ResolutionException::forParameter(
                        $target->reflection,
                        reason: sprintf('caster "%s" is not registered', $attribute->cast),
                    );
                }
                $value = $caster->cast($value);
            }
            return $value;
        }

        throw ResolutionException::forParameter(
            $target->reflection,
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

    /**
     * @param list<Closure():void> $sourceChecks
     * @param-out list<Closure():void> $sourceChecks
     * @return array<string|int,mixed>|object
     */
    private function processMapping(
        ServerRequestInterface $request,
        RequestDataExtractorInterface&MapperInterface $mapper,
        ParameterTarget $target,
        array &$sourceChecks,
    ): array|object {
        $publication = null;
        try {
            $result = $this->mapInput($request, $mapper, $target, $publication);
        } finally {
            // The mapping frame has released the input it owned.
            $sourceChecks = $publication?->finish(is_object($result ?? null) ? $result : null) ?? [];
        }
        return $result;
    }

    /**
     * @param-out ?MappedRequestPublication $publication
     * @return array<string|int,mixed>|object
     */
    private function mapInput(
        ServerRequestInterface $request,
        RequestDataExtractorInterface&MapperInterface $mapper,
        ParameterTarget $target,
        ?MappedRequestPublication &$publication,
    ): array|object {
        $afterMap = $mapper instanceof CallbackMapperInterface ? $mapper->afterMap : null;
        $callbacks = $this->callbacks;
        if ($afterMap !== null && $callbacks === null) {
            throw new InvalidConfigurationException('Request mapping afterMap requires the attribute callback binder.');
        }
        if ($mapper instanceof CasterProviderAwareInterface) {
            $mapper->provider = $this->casterProvider;
        }

        $typeName = $this->resolveTypeName($target);
        $rawData = $mapper->extract($request);
        $this->assertNamedDtoData($typeName, $rawData, $target);
        $this->validateData($typeName, $rawData);
        $data = $mapper->transform($rawData);
        if ($afterMap !== null) {
            // Keep the detached argument alive until the mapping frame completes.
            $argument = ResolutionMetadata::invocationSlots($data);
            $data = $callbacks($afterMap, $argument);
            if (!is_array($data)) {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: 'request mapping afterMap must return an array',
                );
            }
        }
        $this->assertNamedDtoData($typeName, $data, $target);

        if ($typeName === null) {
            $publication = null;
            return $data;
        }

        MappedRequestParameterSourceGuard::assertNoConflicts($typeName, $data);
        $publication = new MappedRequestPublication();
        $provenance = new MappedRequestContext($data, $publication);
        $parameters = RequestParameter::with(ResolutionMetadata::publicParameters($data), $request);

        return ConstructionParameters::capture($parameters, $provenance)->forwardTo($this->factory, $typeName);
    }

    /** @return class-string|null */
    private function resolveTypeName(ParameterTarget $target): ?string
    {
        $type = $target->type;
        if ($type === null || self::containsBuiltinType($type, 'array')) {
            return null;
        }

        $classTypes = $target->typeNames;
        if (count($classTypes) > 1) {
            throw ResolutionException::forParameter(
                $target->reflection,
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
        ParameterTarget $target,
    ): void {
        if ($typeName === null) {
            return;
        }
        foreach ($data as $key => $_value) {
            if (!is_string($key)) {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: 'HTTP DTO mapping accepts only named string keys',
                );
            }
        }
    }
}
