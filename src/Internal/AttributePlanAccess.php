<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetFactoryAccess;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use LogicException;
use WeakReference;

/** Shares one planning cache and connects it to bootstrap observation. @internal */
abstract class AttributePlanAccess extends AttributePlanScope
{
    public static function plans(AttributePlanBuilder $builder): AttributePlans
    {
        return $builder->planning;
    }

    public static function bootstrap(AttributePlanBuilder $builder): ?BootstrapResolutionGuard
    {
        return $builder->bootstrap?->get();
    }

    public static function observeBootstrap(AttributePlanBuilder $builder, ParameterTargetFactory $targets): BootstrapResolutionGuard
    {
        $guard = new BootstrapResolutionGuard($builder->planning, ParameterTargetFactoryAccess::targets($targets));
        if ($builder->bootstrap !== null) {
            throw new LogicException('Attribute plans already observe a bootstrap.');
        }
        $builder->bootstrap = WeakReference::create($guard);
        return $guard;
    }
}
