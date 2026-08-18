<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Selects native virtual-proxy construction for the attributed class. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Proxy {}
