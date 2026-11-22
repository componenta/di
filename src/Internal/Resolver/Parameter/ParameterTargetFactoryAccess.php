<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTargetFactory;

/** Shares parameter declaration metadata and scoped observation across planners. @internal */
abstract class ParameterTargetFactoryAccess extends ParameterTargetFactoryScope
{
    public static function targets(ParameterTargetFactory $factory): ParameterTargets
    {
        return $factory->targets;
    }
}
