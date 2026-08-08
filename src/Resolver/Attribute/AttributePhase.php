<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

/**
 * The two stable extension points of object construction.
 *
 * BeforeInstantiation handlers may configure how the object is created.
 * AfterInstantiation handlers receive the fully constructed real object
 * before it is returned from the factory. For lazy objects and proxies the
 * latter phase runs inside the real-object initializer, not on the shell.
 */
enum AttributePhase: int
{
    case BeforeInstantiation = 100;
    case AfterInstantiation = 200;
}
