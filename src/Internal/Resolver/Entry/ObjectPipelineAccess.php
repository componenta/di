<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;

/** Creates a setup handler with access to its own pipeline's parameter store. @internal */
abstract class ObjectPipelineAccess extends ObjectPipelineScope
{
    public static function setupHandler(
        ObjectPipeline $pipeline,
        CallableInvokerInterface $invoker,
        AttributeCallbackInvoker $callbacks,
        SetUpValueUnwrapperInterface ...$unwrappers,
    ): AttributeHandlerInterface {
        return $pipeline->creation->setupHandler($invoker, $callbacks, ...$unwrappers);
    }
}
