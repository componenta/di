<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

/** How a generated resolver fragment affects the remaining resolver chain. */
enum GeneratedResolverCodeType
{
    /** The resolver is statically irrelevant for the target. */
    case Skip;

    /** The fragment may resolve the parameter; later resolvers remain reachable. */
    case Conditional;

    /** The fragment always resolves or throws; later resolvers are unreachable. */
    case Terminal;
}
