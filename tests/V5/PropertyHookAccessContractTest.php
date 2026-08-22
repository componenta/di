<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;

final readonly class HookAccessDependency {}

final class GetOnlyHookInjectionTarget
{
    private ?HookAccessDependency $captured = null;

    #[Inject]
    public HookAccessDependency $dependency {
        get => $this->captured ?? throw new \LogicException('Dependency is not initialized.');
    }
}

test('DI rejects a virtual injection property without a set hook explicitly', function (): void {
    $container = (new ContainerBuilder())
        ->addService(HookAccessDependency::class, new HookAccessDependency())
        ->build();

    expect(fn() => $container->make(GetOnlyHookInjectionTarget::class))
        ->toThrow(ResolutionException::class, 'without a set hook');
});
