<?php

declare(strict_types=1);

use Componenta\DI\Compile\Autowire\AutowireClassGraph;

interface AutowireAliasGraphContract {}

final readonly class AutowireAliasGraphLeaf {}

final readonly class AutowireAliasGraphImplementation implements AutowireAliasGraphContract
{
    public function __construct(public AutowireAliasGraphLeaf $leaf) {}
}

final readonly class AutowireAliasGraphRoot
{
    public function __construct(public AutowireAliasGraphContract $service) {}
}

it('follows known interface aliases to concrete AOT dependencies', function () {
    $classes = (new AutowireClassGraph([
        AutowireAliasGraphContract::class => AutowireAliasGraphImplementation::class,
    ]))->expand([AutowireAliasGraphRoot::class]);

    expect($classes)
        ->toContain(AutowireAliasGraphRoot::class)
        ->toContain(AutowireAliasGraphImplementation::class)
        ->toContain(AutowireAliasGraphLeaf::class);
});
