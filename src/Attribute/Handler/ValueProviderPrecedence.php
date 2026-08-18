<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

/** Relationship between a declared provider and trusted explicit caller values. */
enum ValueProviderPrecedence
{
    case ExplicitFirst;
    case ProviderFirst;
    case RejectExplicit;
}
