<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final readonly class RepeatedTypedConstructor
{
    public function __construct(
        public DelegatorContractImplementation $first,
        public DelegatorContractImplementation $second,
    ) {}
}
