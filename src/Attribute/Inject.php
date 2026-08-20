<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Explicitly injects a property from the container by declared type. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Inject {}
