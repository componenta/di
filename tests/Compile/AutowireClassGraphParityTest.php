<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Compile\Autowire\AutowireClassGraph;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class DerivedInjectForGraph extends Inject {}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class DerivedSetUpForGraph extends SetUp {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DerivedNoConstructorForGraph extends NoConstructor {}

final readonly class GraphInjectDependency {}

final class GraphInjectRoot
{
    #[DerivedInjectForGraph]
    public GraphInjectDependency $dependency;
}

final readonly class GraphSetUpDependency {}

#[DerivedSetUpForGraph('boot')]
final class GraphSetUpRoot
{
    public function boot(GraphSetUpDependency $dependency): void {}
}

final readonly class GraphSkippedConstructorDependency {}

#[DerivedNoConstructorForGraph]
final class GraphNoConstructorRoot
{
    public function __construct(GraphSkippedConstructorDependency $dependency) {}
}

describe('AutowireClassGraph runtime parity', function () {
    it('includes dependencies referenced through Inject subclasses', function () {
        $classes = (new AutowireClassGraph())->expand([GraphInjectRoot::class]);

        expect($classes)->toContain(GraphInjectRoot::class)
            ->and($classes)->toContain(GraphInjectDependency::class);
    });

    it('includes setup-method dependencies referenced through SetUp subclasses', function () {
        $classes = (new AutowireClassGraph())->expand([GraphSetUpRoot::class]);

        expect($classes)->toContain(GraphSetUpRoot::class)
            ->and($classes)->toContain(GraphSetUpDependency::class);
    });

    it('does not pull constructor dependencies for NoConstructor subclasses', function () {
        $classes = (new AutowireClassGraph())->expand([GraphNoConstructorRoot::class]);

        expect($classes)->toContain(GraphNoConstructorRoot::class)
            ->and($classes)->not->toContain(GraphSkippedConstructorDependency::class);
    });
});
