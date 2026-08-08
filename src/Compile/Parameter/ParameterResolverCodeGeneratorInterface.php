<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Generates the PHP fragment for one concrete parameter resolver instance. */
interface ParameterResolverCodeGeneratorInterface
{
    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode;
}
