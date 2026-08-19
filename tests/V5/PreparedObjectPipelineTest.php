<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final class AuditTrivialEntry {}

final class AuditPrivateConstructorEntry
{
    private function __construct() {}
}

#[NoConstructor]
final class AuditNoConstructorEntry
{
    private function __construct()
    {
        throw new \RuntimeException('Constructor must not run.');
    }
}

final class AuditFastDependency {}

final readonly class AuditFastConstructorEntry
{
    public function __construct(
        public AuditFastDependency $dependency,
        public int $number = 1,
        public string $name = 'default',
    ) {}
}

final class AuditByReferenceConstructorEntry
{
    public function __construct(AuditFastDependency &$dependency) {}
}

final class AuditVariadicConstructorEntry
{
    public function __construct(AuditFastDependency ...$dependencies) {}
}

function cleanupPreparedDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

test('has rejects a concrete class whose constructor cannot be called', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->has(AuditPrivateConstructorEntry::class))->toBeFalse();
});

test('NoConstructor keeps inaccessible constructors resolvable', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->has(AuditNoConstructorEntry::class))->toBeTrue()
        ->and($container->make(AuditNoConstructorEntry::class))->toBeInstanceOf(AuditNoConstructorEntry::class);
});

test('AOT rejects the same inaccessible constructor that runtime cannot resolve', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-ineligible-' . bin2hex(random_bytes(5));

    try {
        expect(fn() => (new ContainerBuilder())->compileFactories(
            [AuditPrivateConstructorEntry::class],
            $directory,
        ))->toThrow(InvalidConfigurationException::class);
    } finally {
        cleanupPreparedDirectory($directory);
    }
});

test('AOT entry methods always delegate execution to ObjectPipeline', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-prepared-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\Prepared' . $suffix;

    try {
        $definitions = (new ContainerBuilder())->compileFactories(
            [AuditTrivialEntry::class, AuditNoConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );

        foreach ([AuditTrivialEntry::class, AuditNoConstructorEntry::class] as $entry) {
            $definition = $definitions[$entry];
            $code = file_get_contents($directory . '/' . $definition->file);

            expect($code)->toBeString()
                ->and($code)->toContain('$this->objects->create(\\' . $entry . '::class, $params)')
                ->and($code)->not->toContain('return new \\' . $entry)
                ->and($code)->not->toContain('$this->container->has(')
                ->and($code)->not->toContain('$this->container->get(')
                ->and($code)->not->toContain('FAST_PATHS');
        }
    } finally {
        cleanupPreparedDirectory($directory);
    }
});

test('compiled plain autowiring uses the same ObjectPipeline semantics for defaults and overrides', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-constructor-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\Constructor' . $suffix;
    $builder = new ContainerBuilder();
    $development = $builder->build();

    try {
        $definitions = $builder->compileFactories(
            [AuditFastConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );
        $definition = $definitions[AuditFastConstructorEntry::class];
        $code = file_get_contents($directory . '/' . $definition->file);

        expect($code)->toBeString()
            ->and($code)->toContain('$this->objects->create(\\' . AuditFastConstructorEntry::class . '::class, $params)')
            ->and($code)->not->toContain('$this->container->has(')
            ->and($code)->not->toContain('$this->container->get(')
            ->and($code)->not->toContain('return new \\' . AuditFastConstructorEntry::class)
            ->and($code)->not->toContain('FAST_PATHS');

        $production = compiledAuditContainer($definitions, $directory);
        $developmentDefault = $development->make(AuditFastConstructorEntry::class);
        $productionDefault = $production->make(AuditFastConstructorEntry::class);
        $developmentOverride = $development->make(AuditFastConstructorEntry::class, ['number' => 42]);
        $productionOverride = $production->make(AuditFastConstructorEntry::class, ['number' => 42]);

        expect([$productionDefault->number, $productionDefault->name])
            ->toBe([$developmentDefault->number, $developmentDefault->name])
            ->and([$productionOverride->number, $productionOverride->name])
            ->toBe([$developmentOverride->number, $developmentOverride->name])
            ->and($productionDefault->dependency)->toBeInstanceOf(AuditFastDependency::class)
            ->and($productionOverride->dependency)->toBeInstanceOf(AuditFastDependency::class);
    } finally {
        cleanupPreparedDirectory($directory);
    }
});

test('unsupported constructor shapes still compile only through the shared ObjectPipeline', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-unsupported-constructor-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\UnsupportedConstructor' . $suffix;

    try {
        $definitions = (new ContainerBuilder())->compileFactories(
            [AuditByReferenceConstructorEntry::class, AuditVariadicConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );

        foreach ([AuditByReferenceConstructorEntry::class, AuditVariadicConstructorEntry::class] as $entry) {
            $definition = $definitions[$entry];
            $code = file_get_contents($directory . '/' . $definition->file);

            expect($code)->toBeString()
                ->and($code)->toContain('$this->objects->create(\\' . $entry . '::class, $params)')
                ->and($code)->not->toContain('return new \\' . $entry)
                ->and($code)->not->toContain('FAST_PATHS');
        }
    } finally {
        cleanupPreparedDirectory($directory);
    }
});

/**
 * @param array<class-string,\Componenta\DI\Compile\Factory\CompiledFactoryDefinition> $definitions
 */
function compiledAuditContainer(array $definitions, string $directory): Container
{
    return ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $definitions],
        ],
        $directory,
    )->build();
}
