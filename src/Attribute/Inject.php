<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Explicitly provides a target value from the container by declared type. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Inject {}
