<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;

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
    $directory = sys_get_temp_dir() . '/componenta-aot-alias-' . bin2hex(random_bytes(5));

    try {
        $compiled = (new ContainerBuilder())
            ->addAlias(AutowireAliasGraphContract::class, AutowireAliasGraphImplementation::class)
            ->compileFactories([AutowireAliasGraphRoot::class], $directory);

        expect($compiled)
            ->toHaveKey(AutowireAliasGraphRoot::class)
            ->toHaveKey(AutowireAliasGraphImplementation::class)
            ->toHaveKey(AutowireAliasGraphLeaf::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('resolves a known alias before filtering an AOT root for eligibility', function () {
    $directory = sys_get_temp_dir() . '/componenta-aot-alias-root-' . bin2hex(random_bytes(5));

    try {
        $compiled = (new ContainerBuilder())
            ->addAlias(AutowireAliasGraphContract::class, AutowireAliasGraphImplementation::class)
            ->compileFactories([AutowireAliasGraphContract::class], $directory);

        expect($compiled)
            ->not->toHaveKey(AutowireAliasGraphContract::class)
            ->toHaveKey(AutowireAliasGraphImplementation::class)
            ->toHaveKey(AutowireAliasGraphLeaf::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
