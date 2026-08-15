<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CustomInvokableLifecycle {}
