<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\AttributePlanAccess;
use Componenta\DI\Internal\Resolver\Parameter\AttributeParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterAttributeScope;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Executes the composed attribute handlers for a parameter. */
final class AttributeParameterResolver extends ParameterAttributeScope implements ParameterResolverInterface
{
    public function __construct(AttributePlanBuilder $plans)
    {
        parent::__construct(new AttributeParameterExecution(AttributePlanAccess::plans($plans)));
    }

    public function supports(ParameterTarget $target): bool
    {
        return $this->execution->supports($target);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return $this->execution->resolveParameter($target, $context);
    }
}
