<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Handler\ValueTransformerHandlerInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Exception\ValueProviderConflictException;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ValueTargetInterface;

/** Provider -> transformers -> final type validation pipeline shared by value targets. */
final readonly class ValuePipeline
{
    /** @param list<ValueFallbackInterface> $fallbacks */
    public function __construct(private array $fallbacks) {}

    public function resolve(
        ValueTargetInterface $target,
        AttributePlan $plan,
        ValueContext $context,
    ): mixed {
        $provider = $plan->one(ValueProvider::class);

        if ($provider !== null) {
            $value = $this->resolveProvider(
                $target,
                $provider->attribute,
                $provider->definition->handler,
                $context,
            );
        } else {
            $result = $this->resolveFallback($target, $context);

            if ($result === null) {
                $this->throwUnresolved($target, $context);
            }

            $value = $result->value;
        }

        foreach ($plan->all(ValueTransformer::class) as $transformer) {
            $handler = $transformer->definition->handler;

            if (!$handler instanceof ValueTransformerHandlerInterface) {
                throw new InvalidConfigurationException(sprintf(
                    'Attribute "%s" declares %s but handler %s does not implement %s.',
                    $transformer->attribute::class,
                    ValueTransformer::class,
                    $handler::class,
                    ValueTransformerHandlerInterface::class,
                ));
            }

            $value = $handler->transform($transformer->attribute, $value, $target, $context);
        }

        if (!$target->accepts($value)) {
            $this->throwTypeMismatch($target, $value, $context);
        }

        return $value;
    }

    private function resolveProvider(
        ValueTargetInterface $target,
        object $attribute,
        object $handler,
        ValueContext $context,
    ): mixed {
        if (!$handler instanceof ValueProviderHandlerInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute "%s" declares %s but handler %s does not implement %s.',
                $attribute::class,
                ValueProvider::class,
                $handler::class,
                ValueProviderHandlerInterface::class,
            ));
        }

        if ($context->resolution->hasMapped($target->name)) {
            throw new ValueProviderConflictException(
                target: $target->declaringContext,
                provider: $attribute::class,
                key: $target->name,
                origin: 'mapped',
            );
        }

        $explicit = self::explicitValue($target, $context);

        if ($explicit !== null) {
            if ($handler->precedence === ValueProviderPrecedence::ExplicitFirst) {
                return $explicit->value;
            }

            if ($handler->precedence === ValueProviderPrecedence::RejectExplicit) {
                throw new ValueProviderConflictException(
                    target: $target->declaringContext,
                    provider: $attribute::class,
                    key: $target->name,
                    origin: 'explicit',
                );
            }
        }

        return $handler->provide($attribute, $target, $context);
    }

    private function resolveFallback(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        foreach ($this->fallbacks as $fallback) {
            if (!$fallback->supports($target)) {
                continue;
            }

            $result = $fallback->resolve($target, $context);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private static function explicitValue(
        ValueTargetInterface $target,
        ValueContext $context,
    ): ?ValueResult {
        $explicit = $context->resolution->explicit;

        if (array_key_exists($target->name, $explicit)) {
            return new ValueResult($explicit[$target->name]);
        }

        if ($target instanceof ParameterTarget && array_key_exists($target->position, $explicit)) {
            return new ValueResult($explicit[$target->position]);
        }

        foreach ($target->typeNames as $typeName) {
            if (!array_key_exists($typeName, $explicit)) {
                continue;
            }

            $value = $explicit[$typeName];

            if (is_object($value) && $target->accepts($value)) {
                return new ValueResult($value);
            }
        }

        return null;
    }

    private function throwUnresolved(ValueTargetInterface $target, ValueContext $context): never
    {
        $reflector = $target->reflector();

        if ($reflector instanceof \ReflectionParameter) {
            throw ResolutionException::forParameter(
                $reflector,
                providedParameters: $context->resolution->visible(),
                resolvedParameters: $context->resolvedParameters,
            );
        }

        if ($reflector instanceof \ReflectionProperty) {
            throw ResolutionException::forProperty(
                $reflector,
                reason: 'no value provider or fallback could resolve the property',
            );
        }

        throw new \LogicException('Unsupported value target reflector.');
    }

    private function throwTypeMismatch(
        ValueTargetInterface $target,
        mixed $value,
        ValueContext $context,
    ): never {
        $reflector = $target->reflector();
        $reason = sprintf(
            'final value of type "%s" does not satisfy the declared target type',
            get_debug_type($value),
        );

        if ($reflector instanceof \ReflectionParameter) {
            throw ResolutionException::forParameter(
                $reflector,
                reason: $reason,
                providedParameters: $context->resolution->visible(),
                resolvedParameters: $context->resolvedParameters,
            );
        }

        if ($reflector instanceof \ReflectionProperty) {
            throw ResolutionException::forProperty($reflector, reason: $reason);
        }

        throw new \LogicException('Unsupported value target reflector.');
    }
}
