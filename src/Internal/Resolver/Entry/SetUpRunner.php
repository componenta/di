<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
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
        private readonly AttributeCallbackInvoker $callbackInvoker,
        SetUpValueUnwrapperInterface ...$valueUnwrappers,
    ) {
        $this->valueUnwrappers = array_values($valueUnwrappers);
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof SetUp || !$target instanceof ReflectionClass) {
            throw new \LogicException('SetUpRunner received an unsupported attribute target.');
        }

        $method = $attribute->method instanceof Closure ? null : self::method($target, $attribute->method);
        $entry = $context->entry ?? throw new \LogicException(
            'SetUp cannot run before object instantiation.',
        );
        ObjectCreationAccess::requireOpenProperties($context);

        if ($method === null) {
            $check = $this->callback($attribute, $target, $context, $entry);
        } else {
            $input = $this->resolutionParameters->get($context);
            [$parameters, $provenance] = $this->providedParameters(
                $attribute,
                ConstructionParameters::invocationArguments($input),
                $method,
                ConstructionParameters::provenance($input),
            );
            $name = $method->getName();
            $check = null;
            if ($provenance !== null && method_exists($entry, $name)) {
                $selected = new ReflectionMethod($entry, $name);
                $check = MappedRequestParameterSourceGuard::watchInvocation($selected, $provenance, $target->getName());
                $this->callbackInvoker->invokeMappedMethod($entry, $selected, $parameters, $provenance);
            } else {
                $this->callableInvoker->call([$entry, $name], $parameters);
            }
        }

        if ($check !== null) {
            $this->resolutionParameters->retainSetupSourceCheck($context, $check);
        }
        $this->resolutionParameters->assertSetupSourcesUnchanged($context);
    }

    /**
     * Attribute values override public surrounding object-creation parameters.
     *
     * @param array<string|int,mixed> $context
     * @return array{array<string|int,mixed>,?MappedRequestContext}
     */
    private function providedParameters(
        SetUp $attribute,
        array $context,
        ReflectionFunctionAbstract $method,
        ?MappedRequestContext $provenance,
    ): array {
        $overrides = ResolutionMetadata::publicParameters($this->unwrapParams($attribute->params, $method));
        return [array_replace($context, $overrides), $provenance?->withoutOverrides($overrides)];
    }

    /** @param ReflectionClass<object> $class */
    private static function method(ReflectionClass $class, string $name): ReflectionMethod
    {
        if (!$class->hasMethod($name)) {
            throw new \LogicException(sprintf(
                'SetUp method "%s::%s()" does not exist.',
                $class->getName(),
                $name,
            ));
        }

        $method = $class->getMethod($name);
        if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
            throw new \LogicException(sprintf(
                'SetUp method "%s::%s()" must be public, concrete and non-static.',
                $class->getName(),
                $name,
            ));
        }

        return $method;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return (Closure():void)|null
     */
    private function callback(
        SetUp $attribute,
        ReflectionClass $class,
        ObjectCreationContext $context,
        object $entry,
    ): ?Closure {
        if (!$attribute->method instanceof Closure) {
            throw new \LogicException('A setup callback must be a Closure.');
        }

        $method = new ReflectionFunction($attribute->method);
        $first = $method->getParameters()[0] ?? null;
        self::assertCallbackParameters($attribute->params, $first?->getName());

        $input = $this->resolutionParameters->get($context);
        $parameters = ConstructionParameters::invocationArguments($input);
        unset($parameters[0]);
        if ($first !== null) {
            unset($parameters[$first->getName()]);
        }

        [$provided, $provenance] = $this->providedParameters(
            $attribute,
            $parameters,
            $method,
            ConstructionParameters::provenance($input),
        );
        $owner = $class->getName();
        $check = $provenance === null ? null : MappedRequestParameterSourceGuard::watchInvocation($method, $provenance, $owner, skipFirst: true);
        $this->callbackInvoker->invoke(
            $attribute->method,
            $entry,
            $provided,
            $owner,
            provenance: $provenance,
        );
        return $check;
    }

    /** @param array<string|int,mixed> $parameters */
    private static function assertCallbackParameters(array $parameters, ?string $reservedName): void
    {
        foreach ($parameters as $key => $value) {
            if (is_int($key) || $key === $reservedName) {
                throw new InvalidConfigurationException(sprintf(
                    'SetUp callback parameter "%s" is reserved for the constructed instance or positional resolution.',
                    $key,
                ));
            }
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function unwrapParams(array $params, ?ReflectionFunctionAbstract $method): array
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
