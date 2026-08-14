<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

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
    public ?GraphSetUpDependency $dependency = null;

    public function boot(GraphSetUpDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}

final readonly class GraphSkippedConstructorDependency {}

#[DerivedNoConstructorForGraph]
final class GraphNoConstructorRoot
{
    public function __construct(GraphSkippedConstructorDependency $dependency) {}
}

function graphParityContainer(array $roots, string $directory): array
{
    $compiled = (new ContainerBuilder())->compileFactories($roots, $directory);
    $container = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => $compiled,
            ],
        ],
        $directory,
    )->build();

    return [$compiled, $container];
}

describe('compiled autowire attribute parity', function () {
    it('includes and resolves dependencies referenced through Inject subclasses', function () {
        $directory = sys_get_temp_dir() . '/componenta-derived-inject-' . bin2hex(random_bytes(5));

        try {
            [$compiled, $container] = graphParityContainer([GraphInjectRoot::class], $directory);

            expect($compiled)
                ->toHaveKey(GraphInjectRoot::class)
                ->toHaveKey(GraphInjectDependency::class)
                ->and($container->make(GraphInjectRoot::class)->dependency)
                ->toBeInstanceOf(GraphInjectDependency::class);
        } finally {
            foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    });

    it('includes and resolves setup-method dependencies referenced through SetUp subclasses', function () {
        $directory = sys_get_temp_dir() . '/componenta-derived-setup-' . bin2hex(random_bytes(5));

        try {
            [$compiled, $container] = graphParityContainer([GraphSetUpRoot::class], $directory);

            expect($compiled)
                ->toHaveKey(GraphSetUpRoot::class)
                ->toHaveKey(GraphSetUpDependency::class)
                ->and($container->make(GraphSetUpRoot::class)->dependency)
                ->toBeInstanceOf(GraphSetUpDependency::class);
        } finally {
            foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    });

    it('does not compile constructor dependencies disabled by NoConstructor subclasses', function () {
        $directory = sys_get_temp_dir() . '/componenta-derived-no-constructor-' . bin2hex(random_bytes(5));

        try {
            [$compiled, $container] = graphParityContainer([GraphNoConstructorRoot::class], $directory);

            expect($compiled)
                ->toHaveKey(GraphNoConstructorRoot::class)
                ->not->toHaveKey(GraphSkippedConstructorDependency::class)
                ->and($container->make(GraphNoConstructorRoot::class))
                ->toBeInstanceOf(GraphNoConstructorRoot::class);
        } finally {
            foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    });
});
