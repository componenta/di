<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\SetUp;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Container;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\GeneratedAttributeCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use ReflectionClass;
use ReflectionMethod;
use Reflector;

/** Runtime and compile-time owner of repeatable class-level #[SetUp]. */
final class SetUpRunner implements CompilableAttributeHandlerInterface
{
    /** @var list<SetUpValueUnwrapperInterface> */
    private readonly array $valueUnwrappers;

    private readonly CallableInvokerInterface $callableInvoker;

    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 0;
    }

    public function __construct(
        CallableInvokerInterface $callableInvoker,
        SetUpValueUnwrapperInterface ...$valueUnwrappers,
    ) {
        $this->callableInvoker = $callableInvoker;
        $this->valueUnwrappers = array_values($valueUnwrappers);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionClass
            && is_a($attributeClass, SetUp::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof SetUp || !$target instanceof ReflectionClass) {
            throw new \LogicException(sprintf(
                '%s received unsupported attribute target %s on %s.',
                self::class,
                $attribute::class,
                get_debug_type($target),
            ));
        }

        $method = self::method($target, $attribute);
        $this->callableInvoker->call(
            [
                $context->entry ?? throw new \LogicException(
                    'SetUp cannot run before object instantiation.',
                ),
                $method->getName(),
            ],
            $this->providedParameters($attribute, $context->parameters),
        );
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        if (!$attribute instanceof SetUp || !$target instanceof ReflectionClass) {
            throw new \LogicException(sprintf(
                '%s received unsupported attribute target %s on %s.',
                self::class,
                $attribute::class,
                get_debug_type($target),
            ));
        }

        if (!$this->callableInvoker instanceof Container) {
            return new GeneratedAttributeCode(
                code: sprintf(
                    '%s->handle(%s, %s, %s);',
                    $context->handlerExpression,
                    $context->attributeExpression,
                    $context->targetExpression,
                    $context->creationExpression,
                ),
                    usesAttribute: true,
                usesTarget: true,
            );
        }

        $parameterGenerator = $context->parameters ?? throw new \LogicException(
            'SetUp code generation requires ParameterCodeGenerator.',
        );
        $method = self::method($target, $attribute);
        $providedVariable = '$' . $context->symbolPrefix . 'Provided';
        $resolutionVariable = '$' . $context->symbolPrefix . 'Parameters';
        $parts = [
            sprintf(
                '%s = %s->providedParameters(%s, %s->parameters);',
                $providedVariable,
                $context->handlerExpression,
                $context->attributeExpression,
                $context->creationExpression,
            ),
            sprintf(
                '%s = new \\%s(%s);',
                $resolutionVariable,
                ParameterResolutionContext::class,
                $providedVariable,
            ),
        ];
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $position = $parameter->getPosition();
            $argumentVariable = sprintf(
                '$%sArgument%d',
                $context->symbolPrefix,
                $position,
            );
            $resultVariable = sprintf(
                '$%sResult%d',
                $context->symbolPrefix,
                $position,
            );
            $resolvedLabel = sprintf(
                '%s_parameter_%d_resolved',
                $context->symbolPrefix,
                $position,
            );
            $targetExpression = $context->parameterTargetExpression($parameter);
            $generated = $parameterGenerator->generate(
                $parameter,
                new ParameterCodeGenerationContext(
                    contextExpression: $resolutionVariable,
                    argumentVariable: $argumentVariable,
                    resultVariable: $resultVariable,
                    targetExpression: $targetExpression,
                    resolvedLabel: $resolvedLabel,
                ),
            );

            if ($generated->usesTarget) {
                $context->requireParameterTarget($parameter);
            }

            $parts[] = $generated->code;
            $arguments[] = $argumentVariable;
        }

        $parts[] = sprintf(
            '%s->%s(%s);',
            $context->entryExpression,
            $method->getName(),
            implode(', ', $arguments),
        );

        return new GeneratedAttributeCode(
            code: implode("\n\n", $parts),
            usesAttribute: true,
        );
    }

    /**
     * Resolves explicit SetUp wrappers and lets attribute values override the
     * object-creation context, matching the runtime callable pipeline.
     *
     * @param array<string|int, mixed> $context
     * @return array<string|int, mixed>
     */
    public function providedParameters(SetUp $attribute, array $context = []): array
    {
        return array_replace($context, $this->unwrapParams($attribute->params));
    }

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
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function unwrapParams(array $params): array
    {
        if ($this->valueUnwrappers === []) {
            return $params;
        }

        $resolved = [];

        foreach ($params as $key => $value) {
            $resolved[$key] = $this->unwrap($value, (string) $key);
        }

        return $resolved;
    }

    private function unwrap(mixed $value, string $key): mixed
    {
        foreach ($this->valueUnwrappers as $unwrapper) {
            if ($unwrapper->supports($value)) {
                return $unwrapper->unwrap($value, $key);
            }
        }

        return $value;
    }
}
