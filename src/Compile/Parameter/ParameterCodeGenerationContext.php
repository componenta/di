<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

use InvalidArgumentException;

/** Variable and expression names available while generating one parameter. */
final class ParameterCodeGenerationContext
{
    public function __construct(
        public readonly string $contextExpression,
        public readonly string $argumentVariable,
        public readonly string $resultVariable,
        public readonly string $targetExpression,
        public readonly string $resolvedLabel,
        public readonly string $resolverListExpression = '$this->parameterResolvers',
        public readonly ?int $resolverSlot = null,
    ) {
        self::assertExpression($contextExpression, 'context expression');
        self::assertVariable($argumentVariable, 'argument variable');
        self::assertVariable($resultVariable, 'result variable');
        self::assertExpression($targetExpression, 'target expression');
        self::assertLabel($resolvedLabel);
        self::assertExpression($resolverListExpression, 'resolver-list expression');

        if ($resolverSlot !== null && $resolverSlot < 0) {
            throw new InvalidArgumentException('Resolver slot must be non-negative.');
        }
    }

    public string $resolverExpression {
        get {
            if ($this->resolverSlot === null) {
                throw new \LogicException('Resolver slot has not been assigned.');
            }

            return sprintf('%s[%d]', $this->resolverListExpression, $this->resolverSlot);
        }
    }

    public function withResolverSlot(int $resolverSlot): self
    {
        return new self(
            contextExpression: $this->contextExpression,
            argumentVariable: $this->argumentVariable,
            resultVariable: $this->resultVariable,
            targetExpression: $this->targetExpression,
            resolvedLabel: $this->resolvedLabel,
            resolverListExpression: $this->resolverListExpression,
            resolverSlot: $resolverSlot,
        );
    }

    private static function assertVariable(string $variable, string $name): void
    {
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $variable) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s "%s".', $name, $variable));
        }
    }

    private static function assertLabel(string $label): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $label) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid PHP label "%s".', $label));
        }
    }

    private static function assertExpression(string $expression, string $name): void
    {
        if ($expression === '' || str_contains($expression, ';')) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $name));
        }
    }
}
