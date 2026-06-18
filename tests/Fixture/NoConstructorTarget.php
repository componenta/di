<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\NoConstructor;

#[NoConstructor]
final class NoConstructorTarget
{
    public string $tag = 'no-ctor';

    public function markTouched(): void
    {
        $this->tag = 'touched';
    }
}
