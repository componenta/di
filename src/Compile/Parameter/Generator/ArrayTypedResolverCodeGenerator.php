<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter\Generator;

use Componenta\DI\Compile\Parameter\EmptyContextResolution;
use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Compile\Parameter\PhpValueExporter;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;

/** Inlines type-keyed lookup from explicit arguments and ambient context. */
final class ArrayTypedResolverCodeGenerator implements ParameterResolverCodeGeneratorInterface
{
    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        if (!$resolver instanceof ArrayTypedResolver) {
            throw new LogicException('ArrayTypedResolverCodeGenerator received an unsupported resolver.');
        }

        $types = PhpValueExporter::export($target->typeNames);
        if ($types === null || $target->typeNames === []) {
            return GeneratedResolverCode::skip();
        }

        $typeVariable = $context->resultVariable . 'Type';
        $valueVariable = $context->resultVariable . 'Value';

        return GeneratedResolverCode::conditional(sprintf(
            <<<'PHP'
foreach (%s as %s) {
    if (array_key_exists(%s, %s->arguments)) {
        %s = %s->arguments[%s];
        if (is_object(%s) && %s->accepts(%s)) {
            %s->consumeArgument(%s);
            %s = %s;
            goto %s;
        }
    }

    if (!array_key_exists(%s, %s->context)) {
        continue;
    }

    %s = %s->context[%s];
    if (is_object(%s) && %s->accepts(%s)) {
        %s = %s;
        goto %s;
    }
}
PHP,
            $types,
            $typeVariable,
            $typeVariable,
            $context->contextExpression,
            $valueVariable,
            $context->contextExpression,
            $typeVariable,
            $valueVariable,
            $context->targetExpression,
            $valueVariable,
            $context->contextExpression,
            $typeVariable,
            $context->argumentVariable,
            $valueVariable,
            $context->resolvedLabel,
            $typeVariable,
            $context->contextExpression,
            $valueVariable,
            $context->contextExpression,
            $typeVariable,
            $valueVariable,
            $context->targetExpression,
            $valueVariable,
            $context->argumentVariable,
            $valueVariable,
            $context->resolvedLabel,
        ), usesTarget: true, emptyContext: EmptyContextResolution::Skip);
    }
}
