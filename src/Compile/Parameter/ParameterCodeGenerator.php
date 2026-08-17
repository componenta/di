<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionResult;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use ReflectionParameter;

/** Specializes the exact runtime resolver chain into a linear PHP block. */
final class ParameterCodeGenerator
{
    public function __construct(
        private readonly ParametersResolver $parameters,
        private readonly ParameterResolverCodeGeneratorRegistry $generators,
    ) {}

    public function generate(
        ReflectionParameter $parameter,
        ParameterCodeGenerationContext $context,
    ): GeneratedParameterCode {
        $target = $this->parameters->target($parameter);
        $unsupportedReason = match (true) {
            $target->variadic
                => 'Variadic parameters are not supported by the DI resolver contract.',
            $target->byReference
                => 'By-reference parameters are not supported by the DI resolver contract.',
            default => null,
        };

        if ($unsupportedReason !== null) {
            return new GeneratedParameterCode(
                code: sprintf(
                    <<<'PHP'
throw \%s::forParameter(
    %s->reflection,
    reason: %s,
    providedParameters: %s->provided,
    resolvedParameters: %s->resolved,
);
PHP,
                    ResolutionException::class,
                    $context->targetExpression,
                    var_export($unsupportedReason, true),
                    $context->contextExpression,
                    $context->contextExpression,
                ),
                usesTarget: true,
                usesDeclaredDefaultWhenEmpty: false,
            );
        }

        $parts = [];
        $usesTarget = false;
        $terminal = false;
        $usesDeclaredDefaultWhenEmpty = false;
        $emptyContextStillSkips = true;

        if (MappedRequestParameterSourceGuard::supportsTarget($target)) {
            $parts[] = sprintf(
                '\\%s::assertTargetContextNoConflicts(%s, %s->provided);',
                MappedRequestParameterSourceGuard::class,
                $context->targetExpression,
                $context->contextExpression,
            );
            $usesTarget = true;
        }

        foreach ($this->parameters->resolverSlotsFor($target) as $slot) {
            $resolver = $this->parameters->resolverList[$slot];
            $resolverContext = $context->withResolverSlot($slot);
            $generator = $this->generators->find($resolver);
            $fragment = $generator?->generate(
                $resolver,
                $target,
                $resolverContext,
            ) ?? $this->runtimeFallback($resolverContext);

            if ($fragment->emptyContext === EmptyContextResolution::Unknown) {
                $emptyContextStillSkips = false;
            } elseif ($fragment->emptyContext === EmptyContextResolution::DeclaredDefault) {
                $usesDeclaredDefaultWhenEmpty = $emptyContextStillSkips;
            }

            if ($fragment->type === GeneratedResolverCodeType::Skip) {
                continue;
            }

            $parts[] = rtrim($fragment->code);
            $usesTarget = $usesTarget || $fragment->usesTarget;

            if ($fragment->type === GeneratedResolverCodeType::Terminal) {
                $terminal = true;
                break;
            }
        }

        if (!$terminal) {
            $parts[] = sprintf(
                <<<'PHP'
throw \%s::forParameter(
    %s->reflection,
    providedParameters: %s->provided,
    resolvedParameters: %s->resolved,
);
PHP,
                ResolutionException::class,
                $context->targetExpression,
                $context->contextExpression,
                $context->contextExpression,
            );
            $usesTarget = true;
        }

        $parts[] = sprintf(
            "%s:\n%s->resolve(%d, %s);",
            $context->resolvedLabel,
            $context->contextExpression,
            $target->position,
            $context->argumentVariable,
        );

        return new GeneratedParameterCode(
            code: implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')),
            usesTarget: $usesTarget,
            usesDeclaredDefaultWhenEmpty: $usesDeclaredDefaultWhenEmpty,
        );
    }

    private function runtimeFallback(
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        return GeneratedResolverCode::conditional(
            sprintf(
                <<<'PHP'
%s = %s->resolveParameter(%s, %s);
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
                $context->resolverExpression,
                $context->targetExpression,
                $context->contextExpression,
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
            ),
            usesTarget: true,
        );
    }
}
