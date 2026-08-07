<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Attribute;

use Closure;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
use InvalidArgumentException;
use ReflectionParameter;

/** Expressions and collaborators available while compiling one invocation. */
final readonly class AttributeCodeGenerationContext
{
    /** @var Closure(ReflectionParameter): string|null */
    private ?Closure $parameterTargetExpressionResolver;

    /** @var Closure(ReflectionParameter): void|null */
    private ?Closure $parameterTargetRequirement;

    /**
     * @param (Closure(ReflectionParameter): string)|null $parameterTargetExpressionResolver
     * @param (Closure(ReflectionParameter): void)|null   $parameterTargetRequirement
     */
    public function __construct(
        public string $creationExpression,
        public string $entryExpression,
        public string $handlerExpression,
        public string $attributeExpression,
        public string $targetExpression,
        public string $symbolPrefix,
        public ?ParameterCodeGenerator $parameters = null,
        ?Closure $parameterTargetExpressionResolver = null,
        ?Closure $parameterTargetRequirement = null,
    ) {
        self::assertExpression($creationExpression, 'creation expression');
        self::assertExpression($entryExpression, 'entry expression');
        self::assertExpression($handlerExpression, 'handler expression');
        self::assertExpression($attributeExpression, 'attribute expression');
        self::assertExpression($targetExpression, 'target expression');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $symbolPrefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid generated symbol prefix "%s".',
                $symbolPrefix,
            ));
        }

        $this->parameterTargetExpressionResolver = $parameterTargetExpressionResolver;
        $this->parameterTargetRequirement = $parameterTargetRequirement;
    }

    public function parameterTargetExpression(ReflectionParameter $parameter): string
    {
        if ($this->parameterTargetExpressionResolver === null) {
            throw new \LogicException(
                'Parameter target expression resolver is required for this attribute.',
            );
        }

        $expression = ($this->parameterTargetExpressionResolver)($parameter);
        self::assertExpression($expression, 'parameter target expression');

        return $expression;
    }

    public function requireParameterTarget(ReflectionParameter $parameter): void
    {
        if ($this->parameterTargetRequirement === null) {
            throw new \LogicException(
                'Parameter target requirement callback is required for this attribute.',
            );
        }

        ($this->parameterTargetRequirement)($parameter);
    }

    private static function assertExpression(string $expression, string $name): void
    {
        if ($expression === '' || str_contains($expression, ';')) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $name));
        }
    }
}
