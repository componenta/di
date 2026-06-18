<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\MapQueryString;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ClassDefaultMapMapper extends MapQueryString
{
    protected(set) array $map = ['class_default' => 'class_default_field'];
}
