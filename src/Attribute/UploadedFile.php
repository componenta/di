<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class UploadedFile
{
    public function __construct(public string $name) {}
}
