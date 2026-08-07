<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Attribute;

/** PHP fragment and compile-time lifecycle effects for one attribute. */
final readonly class GeneratedAttributeCode
{
    public function __construct(
        public string $code,
        public bool $usesAttribute = false,
        public bool $usesTarget = false,
        public bool $disablesConstructor = false,
    ) {}
}
