<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class HookAccessDependency {}

/** @return non-empty-string */
function getOnlyHookInjectionTarget(): string
{
    $class = __NAMESPACE__ . '\\GetOnlyHookInjectionTarget';

    if (!class_exists($class, false)) {
        eval(<<<'PHP'
namespace Componenta\DI\Tests\V5;

final class GetOnlyHookInjectionTarget
{
    private ?HookAccessDependency $captured = null;

    #[\Componenta\DI\Attribute\Inject]
    public HookAccessDependency $dependency {
        get => $this->captured ?? throw new \LogicException('Dependency is not initialized.');
    }
}
PHP);
    }

    if (!class_exists($class, false)) {
        throw new \LogicException('Failed to define the get-only property hook fixture.');
    }

    return $class;
}

test('DI rejects a virtual injection property without a set hook explicitly', function (): void {
    $container = (new ContainerBuilder())
        ->addService(HookAccessDependency::class, new HookAccessDependency())
        ->build();

    expect(fn() => $container->make(getOnlyHookInjectionTarget()))
        ->toThrow(ResolutionException::class, 'without a set hook');
});
