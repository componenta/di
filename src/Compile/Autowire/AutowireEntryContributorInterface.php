<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

/** Supplies known autowiring roots to the production container compiler. */
interface AutowireEntryContributorInterface
{
    /** @return iterable<AutowireEntry> */
    public function entries(): iterable;
}
