<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

/** Compile-time effect of one resolver when no runtime parameters are provided. */
enum EmptyContextResolution
{
    /** The resolver cannot produce a value from an empty runtime context. */
    case Skip;

    /** The resolver deterministically selects the parameter's declared default. */
    case DeclaredDefault;

    /** The resolver may still produce a value or throw without caller input. */
    case Unknown;
}
