<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

/** Determines how request mappers handle the same key from multiple sources. */
enum RequestDataConflictPolicy: string
{
    /** Reject ambiguous input instead of silently changing its provenance. */
    case Reject = 'reject';

    /** Keep the value from the first source in mapper-defined source order. */
    case FirstWins = 'first_wins';

    /** Keep the value from the last source, matching the legacy merge behavior. */
    case LastWins = 'last_wins';
}
