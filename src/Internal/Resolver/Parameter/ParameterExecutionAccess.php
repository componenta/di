<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Parameter\ParametersResolver;

/** Supplies preparation and argument execution without resolver registration. @internal */
abstract class ParameterExecutionAccess extends ParameterExecutionScope
{
    public static function for(ParametersResolver $resolver): ParameterExecution
    {
        return $resolver->execution;
    }
}
