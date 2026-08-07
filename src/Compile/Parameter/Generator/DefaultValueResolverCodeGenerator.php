<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter\Generator;

use Componenta\DI\Compile\Parameter\EmptyContextResolution;
use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;

/** Emits a terminal assignment for a declared parameter default. */
final class DefaultValueResolverCodeGenerator implements ParameterResolverCodeGeneratorInterface
{
    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        if (!$resolver instanceof DefaultValueResolver) {
            throw new LogicException('DefaultValueResolverCodeGenerator received an unsupported resolver.');
        }

        if (!$target->hasDefault) {
            return GeneratedResolverCode::skip();
        }

        $value = $context->targetExpression . '->default';

        return GeneratedResolverCode::terminal(
            sprintf(
                "%s = %s;\ngoto %s;",
                $context->argumentVariable,
                $value,
                $context->resolvedLabel,
            ),
            usesTarget: true,
            emptyContext: EmptyContextResolution::DeclaredDefault,
        );
    }
}
