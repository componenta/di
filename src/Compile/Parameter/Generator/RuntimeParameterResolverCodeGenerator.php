<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter\Generator;

use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionResult;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Calls one exact runtime resolver slot without rebuilding or walking the
 * parameter resolver chain.
 */
final readonly class RuntimeParameterResolverCodeGenerator implements ParameterResolverCodeGeneratorInterface
{
    public function __construct(
        private bool $terminal,
    ) {}

    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        $call = sprintf(
            '%s->resolveParameter(%s, %s)',
            $context->resolverExpression,
            $context->targetExpression,
            $context->contextExpression,
        );

        if (!$this->terminal) {
            return GeneratedResolverCode::conditional(sprintf(
                <<<'PHP'
%s = %s;
if (%s !== null) {
    %s = \%s::validate(
        %s,
        %s,
        %s,
        %s,
    );
    %s = %s[1];
    goto %s;
}
PHP,
                $context->resultVariable,
                $call,
                $context->resultVariable,
                $context->resultVariable,
                ParameterResolutionResult::class,
                $context->resultVariable,
                $context->resolverExpression,
                $context->targetExpression,
                $context->contextExpression,
                $context->argumentVariable,
                $context->resultVariable,
                $context->resolvedLabel,
            ), usesTarget: true);
        }

        return GeneratedResolverCode::terminal(sprintf(
            <<<'PHP'
%s = %s;
if (%s === null) {
    throw \%s::forParameter(
        %s->reflection,
        providedParameters: %s->provided,
        resolvedParameters: %s->resolved,
    );
}

%s = \%s::validate(
    %s,
    %s,
    %s,
    %s,
);
%s = %s[1];
goto %s;
PHP,
            $context->resultVariable,
            $call,
            $context->resultVariable,
            ResolutionException::class,
            $context->targetExpression,
            $context->contextExpression,
            $context->contextExpression,
            $context->resultVariable,
            ParameterResolutionResult::class,
            $context->resultVariable,
            $context->resolverExpression,
            $context->targetExpression,
            $context->contextExpression,
            $context->argumentVariable,
            $context->resultVariable,
            $context->resolvedLabel,
        ), usesTarget: true);
    }
}
