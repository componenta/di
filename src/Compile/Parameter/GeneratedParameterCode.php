<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

/** Complete generated resolution block for one callable parameter. */
final readonly class GeneratedParameterCode
{
    public function __construct(
        public string $code,
        public bool $usesTarget,
        public bool $usesDeclaredDefaultWhenEmpty,
    ) {}
}
