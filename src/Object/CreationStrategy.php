<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

enum CreationStrategy
{
    case Eager;
    case Lazy;
    case Proxy;
}
