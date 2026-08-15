<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

/** One build-time root whose factory should be compiled. */
final readonly class AutowireEntry
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
    ) {}
}
