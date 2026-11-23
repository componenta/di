<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class ColdConstructorInput {}
