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

/** Provider -> transformer chain -> final declared-type validation. */
final readonly class ValuePipeline
{
    public function __construct(private ValueFallbackRegistry $fallbacks) {}

    public function resolve(ValueTargetInterface $target, AttributePlan $plan, ValueContext $context): mixed
    {
        $provider = $plan->one(ValueProvider::class);
        if ($provider !== null) {
            $value = $this->provider($target, $provider->attribute, $provider->definition->handler, $context);
        } else {
            $result = $this->fallback($target, $context);
            if ($result === null) {
                $this->unresolved($target, $context);
            }
            $value = $result->value;
        }

        foreach ($plan->all(ValueTransformer::class) as $usage) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof ValueTransformerHandlerInterface) {
                throw new InvalidConfigurationException(sprintf(
                    'Attribute %s declares ValueTransformer but handler %s does not implement %s.',
                    $usage->attribute::class,
                    $handler::class,
                    ValueTransformerHandlerInterface::class,
                ));
            }
            $value = $handler->transform($usage->attribute, $value, $target, $context);
        }

        if (!$target->accepts($value)) {
            $this->typeMismatch($target, $value, $context);
        }

        return $value;
    }

    private function provider(ValueTargetInterface $target, object $attribute, object $handler, ValueContext $context): mixed
    {
        if (!$handler instanceof ValueProviderHandlerInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute %s declares ValueProvider but handler %s does not implement %s.',
                $attribute::class,
                $handler::class,
                ValueProviderHandlerInterface::class,
            ));
        }

        if ($context->resolution->hasMapped($target->name)) {
            throw new ValueProviderConflictException($target->declaringContext, $attribute::class, $target->name, 'mapped');
        }

        $explicit = self::explicit($target, $context);
        if ($explicit !== null) {
            if ($handler->precedence === ValueProviderPrecedence::ExplicitFirst) {
                return $explicit->value;
            }
            if ($handler->precedence === ValueProviderPrecedence::RejectExplicit) {
                throw new ValueProviderConflictException($target->declaringContext, $attribute::class, $target->name, 'explicit');
            }
        }

        return $handler->provide($attribute, $target, $context);
    }

    private function fallback(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        foreach ($this->fallbacks->fallbacks() as $fallback) {
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

    private static function explicit(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        $values = $context->resolution->explicit;
        if (array_key_exists($target->name, $values)) {
            return new ValueResult($values[$target->name]);
        }
        if ($target instanceof ParameterTarget && array_key_exists($target->position, $values)) {
            return new ValueResult($values[$target->position]);
        }
        foreach ($target->typeNames as $typeName) {
            if (array_key_exists($typeName, $values)) {
                return new ValueResult($values[$typeName]);
            }
        }
        return null;
    }

    private function unresolved(ValueTargetInterface $target, ValueContext $context): never
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
            throw ResolutionException::forProperty($reflector, reason: 'no value provider or fallback resolved the property');
        }
        throw new \LogicException('Unsupported value target.');
    }

    private function typeMismatch(ValueTargetInterface $target, mixed $value, ValueContext $context): never
    {
        $reflector = $target->reflector();
        $reason = sprintf('final value type %s does not satisfy the declared target type', get_debug_type($value));
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
        throw new \LogicException('Unsupported value target.');
    }
}
