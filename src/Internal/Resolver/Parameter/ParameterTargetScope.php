<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

/** @internal */
abstract class ParameterTargetScope
{
    protected ?ParameterAttributeObservation $attributeObserver = null;
    protected function __construct(protected readonly ParameterDeclaration $declaration) {}

}
