<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Resolver\Attribute\AttributeProcessor;

/** Supplies phase execution to the object creation pipeline. @internal */
abstract class ObjectAttributeAccess extends ObjectAttributeScope
{
    public static function executor(AttributeProcessor $processor): AttributeExecution
    {
        return $processor->execution;
    }
}
