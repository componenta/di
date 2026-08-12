<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

interface ExplicitAliasBindingContract {}

final readonly class ExplicitAliasBindingLeaf {}

final readonly class ExplicitAliasBindingImplementation implements ExplicitAliasBindingContract
{
    public function __construct(public ExplicitAliasBindingLeaf $leaf) {}
}

final readonly class ExplicitAliasBindingRoot
{
    public function __construct(public ExplicitAliasBindingContract $service) {}
}

it('excludes the canonical target of an explicitly bound aliased service from AOT planning', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-explicit-alias-aot-' . bin2hex(random_bytes(5));
    $service = new ExplicitAliasBindingImplementation(new ExplicitAliasBindingLeaf());

    try {
        $builder = (new ContainerBuilder())
            ->addAlias(ExplicitAliasBindingContract::class, ExplicitAliasBindingImplementation::class)
            ->addService(ExplicitAliasBindingContract::class, $service);

        $factories = $builder->compileFactories(
            [ExplicitAliasBindingRoot::class],
            $directory,
            maxShardBytes: 1,
            namespace: 'Componenta\\DI\\Tests\\ExplicitAliasGenerated',
        );

        expect($factories)
            ->toHaveKey(ExplicitAliasBindingRoot::class)
            ->not->toHaveKey(ExplicitAliasBindingImplementation::class)
            ->and($builder->invokables)->not->toContain(ExplicitAliasBindingLeaf::class);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                    ConfigKey::ALIASES => [
                        ExplicitAliasBindingContract::class => ExplicitAliasBindingImplementation::class,
                    ],
                    ConfigKey::SERVICES => [
                        ExplicitAliasBindingContract::class => $service,
                    ],
                    ConfigKey::INVOKABLES => $builder->invokables,
                ],
            ],
            $directory,
        )->build();

        $root = $container->make(ExplicitAliasBindingRoot::class);

        expect($root->service)->toBe($service);
    } finally {
        foreach (glob($directory . '/' . CompiledFactoryShardCompiler::FILE_PREFIX . '*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
