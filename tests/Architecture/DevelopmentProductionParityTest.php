<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;

final readonly class DevelopmentProductionParityDependency {}

final readonly class DevelopmentProductionParityInjected {}

#[SetUp('initialize')]
final class DevelopmentProductionParityEntry
{
    #[Inject]
    public DevelopmentProductionParityInjected $injected;

    public bool $initialized = false;

    public bool $decorated = false;

    public function __construct(
        public DevelopmentProductionParityDependency $dependency,
        public string $value = 'default',
    ) {}

    public function initialize(DevelopmentProductionParityInjected $injected): void
    {
        $this->initialized = $injected === $this->injected;
    }
}

final readonly class DevelopmentProductionParityDynamic
{
    public function __construct(
        public DevelopmentProductionParityDependency $dependency,
    ) {}
}

final readonly class DevelopmentProductionParityInvokable {}

final readonly class DevelopmentProductionParityExplicit
{
    public function __construct(public string $value) {}
}

final class DevelopmentProductionParityExplicitFactory
{
    /** @param array<string|int, mixed> $params */
    public static function make(
        ContainerValue $container,
        array $params = [],
    ): DevelopmentProductionParityExplicit {
        return new DevelopmentProductionParityExplicit(
            value: $params['value'] ?? $container->config->get('parity.factory-value'),
        );
    }
}

final class DevelopmentProductionParityDelegator
{
    public function __invoke(
        DevelopmentProductionParityEntry $entry,
    ): DevelopmentProductionParityEntry {
        $entry->decorated = true;

        return $entry;
    }
}

/** @return array<string, mixed> */
function developmentProductionParityDependencies(): array
{
    return [
        ConfigKey::FACTORIES => [
            DevelopmentProductionParityExplicit::class => [
                DevelopmentProductionParityExplicitFactory::class,
                'make',
            ],
        ],
        ConfigKey::INVOKABLES => [
            DevelopmentProductionParityInvokable::class,
        ],
        ConfigKey::ALIASES => [
            'parity.entry' => DevelopmentProductionParityEntry::class,
        ],
        ConfigKey::DELEGATORS => [
            DevelopmentProductionParityEntry::class => [
                DevelopmentProductionParityDelegator::class,
            ],
        ],
        ConfigKey::SERVICES => [
            'parity.null' => null,
        ],
    ];
}

/** @return array<string, mixed> */
function developmentProductionParitySnapshot(Container $container): array
{
    $entry = $container->get('parity.entry');
    $fresh = $container->make(DevelopmentProductionParityEntry::class, [
        'value' => 'provided',
    ]);
    $explicit = $container->make(DevelopmentProductionParityExplicit::class, [
        'value' => 'provided',
    ]);

    try {
        $container->get('parity.missing');
        $missing = null;
    } catch (\Throwable $exception) {
        $missing = [$exception::class, $exception->getMessage()];
    }

    return [
        'entry-class' => $entry::class,
        'entry-cached' => $entry === $container->get('parity.entry'),
        'entry-dependency' => $entry->dependency::class,
        'entry-injected' => $entry->injected::class,
        'entry-initialized' => $entry->initialized,
        'entry-decorated' => $entry->decorated,
        'fresh-class' => $fresh::class,
        'fresh-distinct' => $fresh !== $entry,
        'fresh-value' => $fresh->value,
        'fresh-decorated' => $fresh->decorated,
        'dynamic-dependency' => $container
            ->make(DevelopmentProductionParityDynamic::class)
            ->dependency::class,
        'invokable-class' => $container
            ->get(DevelopmentProductionParityInvokable::class)::class,
        'explicit-value' => $explicit->value,
        'null-present' => $container->has('parity.null'),
        'null-value' => $container->get('parity.null'),
        'call-result' => $container->call(
            static fn (
                DevelopmentProductionParityDependency $dependency,
                string $value = 'fallback',
            ): string => $dependency::class . ':' . $value,
            ['value' => 'provided'],
        ),
        'missing' => $missing,
    ];
}

it('keeps development reflection and production compiled containers observably equivalent', function (): void {
    $directory = sys_get_temp_dir()
        . '/componenta-development-production-parity-'
        . bin2hex(random_bytes(5));
    $dependencies = developmentProductionParityDependencies();
    $configData = [
        'parity.factory-value' => 'configured',
        ConfigKey::DEPENDENCIES => $dependencies,
    ];

    try {
        $development = ContainerBuilder::configure(new Config($configData))->build();

        $compiler = ContainerBuilder::configure(new Config($configData));
        $compiledFactories = $compiler->compileFactories([
            DevelopmentProductionParityEntry::class,
        ], $directory);
        $productionDependencies = $dependencies;
        $productionDependencies[ConfigKey::FACTORIES] = array_replace(
            $compiledFactories,
            $dependencies[ConfigKey::FACTORIES],
        );
        $productionInvokables = $dependencies[ConfigKey::INVOKABLES];

        foreach ($compiler->invokables as $class) {
            if (!in_array($class, $productionInvokables, true)) {
                $productionInvokables[] = $class;
            }
        }

        $productionDependencies[ConfigKey::INVOKABLES] = $productionInvokables;
        $productionDependencies = ContainerBuilder::normalizeDependencies(
            $productionDependencies,
        );

        foreach ($productionDependencies[ConfigKey::FACTORIES] as $id => $factory) {
            if ($factory instanceof CompiledFactoryDefinition) {
                $productionDependencies[ConfigKey::FACTORIES][$id] = $factory->encode();
            }
        }

        $production = ContainerBuilder::configureFromCache(
            new Config($configData),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ContainerBuilder::CACHE_VALIDATED_KEY => true,
                ConfigKey::DEPENDENCIES => $productionDependencies,
            ],
            $directory,
        )->build();

        expect(developmentProductionParitySnapshot($production))
            ->toBe(developmentProductionParitySnapshot($development));
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
});
