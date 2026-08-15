<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;

final class BuilderReplacementDefaultParameterTarget
{
    public function __construct(public int $value = 5) {}
}

final class BuilderReplacementInjectedDependency {}

final class BuilderReplacementInjectedTarget
{
    #[Inject]
    public BuilderReplacementInjectedDependency $dependency;
}

it('replaceParameterResolvers removes the default parameter resolver pipeline', function (): void {
    $default = (new ContainerBuilder())->build();
    expect($default->make(BuilderReplacementDefaultParameterTarget::class)->value)->toBe(5);

    $replaced = (new ContainerBuilder())
        ->replaceParameterResolvers()
        ->build();

    expect(fn() => $replaced->make(BuilderReplacementDefaultParameterTarget::class))
        ->toThrow(ResolutionException::class);
});

it('replaceAttributeHandlers removes the default attribute handler pipeline', function (): void {
    $dependency = new BuilderReplacementInjectedDependency();
    $default = (new ContainerBuilder())
        ->addService(BuilderReplacementInjectedDependency::class, $dependency)
        ->build();
    expect($default->make(BuilderReplacementInjectedTarget::class)->dependency)->toBe($dependency);

    $replaced = (new ContainerBuilder())
        ->addService(BuilderReplacementInjectedDependency::class, $dependency)
        ->replaceAttributeHandlers()
        ->build();
    $entry = $replaced->make(BuilderReplacementInjectedTarget::class);

    expect(isset($entry->dependency))->toBeFalse();
});
