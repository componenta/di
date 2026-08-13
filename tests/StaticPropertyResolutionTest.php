<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Exception\ResolutionException;

final readonly class StaticPropertyResolutionDependency {}

final class StaticPropertyResolutionTarget
{
    #[Inject]
    public static StaticPropertyResolutionDependency $dependency;
}

test('DI property handlers reject static properties instead of silently ignoring them', function () {
    expect(fn() => minimalContainer()->make(StaticPropertyResolutionTarget::class))
        ->toThrow(ResolutionException::class, 'static properties are not supported');
});
