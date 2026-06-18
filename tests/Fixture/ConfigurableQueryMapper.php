<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\MapQueryString;

/**
 * Test subclass that exposes request extraction and mapping configuration via
 * constructor - avoids anonymous-class gymnastics in every test.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ConfigurableQueryMapper extends MapQueryString
{
    public function __construct(
        array $map = [],
        array $attributes = [],
        array $files = [],
        array $cast = [],
        array $defaults = [],
        array $sortMap = [],
        array $exclude = [],
    ) {
        parent::__construct($map);
        $this->attributes = $attributes;
        $this->files = $files;
        $this->cast = $cast;
        $this->defaults = $defaults;
        $this->sortMap = $sortMap;
        $this->exclude = $exclude;
    }
}
