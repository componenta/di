<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter\Generator;

use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;

/**
 * Compiles the autowire type, then calls the exact resolver slot directly.
 *
 * The resolver instance owns its container dependency. The generated code
 * must not replace it with the compiled factory's shard because users
 * may register multiple AutowireByTypeResolver instances with different
 * container state.
 */
final class AutowireByTypeResolverCodeGenerator implements ParameterResolverCodeGeneratorInterface
{
    private readonly RuntimeParameterResolverCodeGenerator $delegate;

    public function __construct()
    {
        $this->delegate = new RuntimeParameterResolverCodeGenerator(terminal: false);
    }

    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        if (!$resolver instanceof AutowireByTypeResolver) {
            throw new LogicException('AutowireByTypeResolverCodeGenerator received an unsupported resolver.');
        }

        return $this->delegate->generate($resolver, $target, $context);
    }
}
