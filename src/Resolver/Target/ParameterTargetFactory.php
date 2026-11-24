<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetFactoryScope;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargets;
use ReflectionParameter;

/**
 * Creates parameter targets and reuses metadata for named declarations.
 */
final class ParameterTargetFactory extends ParameterTargetFactoryScope
{
    public function __construct()
    {
        parent::__construct(new ParameterTargets());
    }

    public function create(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->targets->create($parameter);
    }
}
