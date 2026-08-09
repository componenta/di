<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/** Marks a class as a build-time compiled-autowiring root. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Autowire
{
}
