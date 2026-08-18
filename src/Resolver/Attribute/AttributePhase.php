<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

/** Stable extension phases around object instantiation. */
enum AttributePhase: int
{
    case BeforeInstantiation = 100;
    case AfterInstantiation = 200;
}
