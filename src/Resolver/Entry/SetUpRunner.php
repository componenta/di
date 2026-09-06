<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\SetUp;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Reflector;

/** Runtime owner of repeatable class-level #[SetUp]. */
final class SetUpRunner implements AttributeHandlerInterface
{
    /** @var list<SetUpValueUnwrapperInterface> */
    private readonly array $valueUnwrappers;

    public function __construct(
        private readonly CallableInvokerInterface $callableInvoker,
        private readonly ObjectResolutionParameterStore $resolutionParameters,
        SetUpValueUnwrapperInterface ...$valueUnwrappers,
    ) {
        $this->valueUnwrappers = array_values($valueUnwrappers);
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof SetUp || !$target instanceof ReflectionClass) {
            throw new \LogicException('SetUpRunner received an unsupported attribute target.');
        }

        $method = self::method($target, $attribute);
        $entry = $context->entry ?? throw new \LogicException(
            'SetUp cannot run before object instantiation.',
        );

        // Raw provenance stays in the private resolution store so mapped-request
        // security checks reach SetUp parameters without exposing metadata to
        // custom object handlers through ObjectCreationContext.
        $this->callableInvoker->call(
            [$entry, $method->getName()],
            $this->providedParameters(
                $attribute,
                $this->resolutionParameters->get($context),
                $method,
            ),
        );
    }

    /**
     * Attribute values override public surrounding object-creation parameters.
     * DI-owned metadata is always preserved and cannot be overwritten by SetUp.
     *
     * @param array<string|int,mixed> $context
     * @return array<string|int,mixed>
     */
    public function providedParameters(
        SetUp $attribute,
        array $context = [],
        ?ReflectionMethod $method = null,
    ): array {
        return ResolutionMetadata::mergePublicPreservingInternal(
            $context,
            $this->unwrapParams($attribute->params, $method),
        );
    }

    /** @param ReflectionClass<object> $class */
    private static function method(ReflectionClass $class, SetUp $attribute): ReflectionMethod
    {
        if (!$class->hasMethod($attribute->method)) {
            throw new \LogicException(sprintf(
                'SetUp method "%s::%s()" does not exist.',
                $class->getName(),
                $attribute->method,
            ));
        }

        $method = $class->getMethod($attribute->method);
        if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
            throw new \LogicException(sprintf(
                'SetUp method "%s::%s()" must be public, concrete and non-static.',
                $class->getName(),
                $attribute->method,
            ));
        }

        return $method;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function unwrapParams(array $params, ?ReflectionMethod $method): array
    {
        if ($this->valueUnwrappers === [] || $params === []) {
            return $params;
        }

        /** @var array<string,ReflectionParameter> $parameters */
        $parameters = [];
        if ($method !== null) {
            foreach ($method->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }
        }

        $resolved = [];
        foreach ($params as $key => $value) {
            $resolved[$key] = $this->unwrap(
                $value,
                $key,
                $parameters[$key] ?? null,
            );
        }

        return $resolved;
    }

    private function unwrap(
        mixed $value,
        string $key,
        ?ReflectionParameter $parameter,
    ): mixed {
        foreach ($this->valueUnwrappers as $unwrapper) {
            if ($unwrapper->supports($value)) {
                return $unwrapper->unwrap($value, $key, $parameter);
            }
        }

        return $value;
    }
}
