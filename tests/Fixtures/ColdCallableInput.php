<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ColdCallableInput {}
