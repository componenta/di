<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter\Generator;

use Componenta\DI\Compile\Parameter\EmptyContextResolution;
use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use ReflectionNamedType;

/** Inlines explicit invocation-argument lookup by name or position. */
final class ArrayResolverCodeGenerator implements ParameterResolverCodeGeneratorInterface
{
    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        if (!$resolver instanceof ArrayResolver) {
            throw new LogicException('ArrayResolverCodeGenerator received an unsupported resolver.');
        }

        $name = var_export($target->name, true);
        $position = $target->position;
        $requiresTypeCheck = !($target->type === null
            || ($target->type instanceof ReflectionNamedType
                && $target->type->getName() === 'mixed'));

        $byName = $this->branch(
            key: $name,
            description: sprintf('value provided for "$%s" does not satisfy declared type', $target->name),
            context: $context,
            requiresTypeCheck: $requiresTypeCheck,
        );

        $byPosition = $this->branch(
            key: (string) $position,
            description: sprintf('value provided at position %d does not satisfy declared type', $position),
            context: $context,
            requiresTypeCheck: $requiresTypeCheck,
        );

        return GeneratedResolverCode::conditional(
            $byName . "\n\n" . $byPosition,
            usesTarget: $requiresTypeCheck,
            emptyContext: EmptyContextResolution::Skip,
        );
    }

    private function branch(
        string $key,
        string $description,
        ParameterCodeGenerationContext $context,
        bool $requiresTypeCheck,
    ): string {
        $arguments = $context->contextExpression . '->arguments';
        $value = $context->resultVariable;
        $assign = sprintf(
            "%s->consumeArgument(%s);\n%s = %s;\ngoto %s;",
            $context->contextExpression,
            $key,
            $context->argumentVariable,
            $value,
            $context->resolvedLabel,
        );

        if (!$requiresTypeCheck) {
            return sprintf(
                <<<'PHP'
if (array_key_exists(%s, %s)) {
    %s = %s[%s];
    %s
}
PHP,
                $key,
                $arguments,
                $value,
                $arguments,
                $key,
                self::indent($assign),
            );
        }

        return sprintf(
            <<<'PHP'
if (array_key_exists(%s, %s)) {
    %s = %s[%s];

    if (%s->accepts(%s)) {
        %s
    }

    throw \%s::forParameter(
        %s->reflection,
        reason: %s,
        providedParameters: %s->provided,
        resolvedParameters: %s->resolved,
    );
}
PHP,
            $key,
            $arguments,
            $value,
            $arguments,
            $key,
            $context->targetExpression,
            $value,
            self::indent($assign, 8),
            ResolutionException::class,
            $context->targetExpression,
            var_export($description, true),
            $context->contextExpression,
            $context->contextExpression,
        );
    }

    private static function indent(string $code, int $spaces = 4): string
    {
        $indent = str_repeat(' ', $spaces);

        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
