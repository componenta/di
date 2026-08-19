<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;

final readonly class StaticInjectedDependency {}

final class StaticInjectedTarget
{
    #[Inject]
    public static StaticInjectedDependency $dependency;
}

test('DI property handlers reject static properties explicitly', function (): void {
    expect(fn() => (new ContainerBuilder())->build()->make(StaticInjectedTarget::class))
        ->toThrow(ResolutionException::class, 'static properties are not supported');
});
