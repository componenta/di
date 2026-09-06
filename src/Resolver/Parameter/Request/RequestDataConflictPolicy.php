<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

/** Determines how #[MapRequest] handles duplicate keys from multiple sources. */
enum RequestDataConflictPolicy: string
{
    case Reject = 'reject';
    case FirstWins = 'first_wins';
}
