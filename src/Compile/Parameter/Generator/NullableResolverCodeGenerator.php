<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter\Generator;

use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;

/** Emits a terminal null assignment for nullable parameters. */
final class NullableResolverCodeGenerator implements ParameterResolverCodeGeneratorInterface
{
    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        if (!$resolver instanceof NullableResolver) {
            throw new LogicException('NullableResolverCodeGenerator received an unsupported resolver.');
        }

        return $target->allowsNull
            ? GeneratedResolverCode::terminal(sprintf(
                "%s = null;\ngoto %s;",
                $context->argumentVariable,
                $context->resolvedLabel,
            ))
            : GeneratedResolverCode::skip();
    }
}
