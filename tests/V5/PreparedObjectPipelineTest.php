<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

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

final class AuditNumberConventionResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'number';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
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
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('AOT emits a direct constructor only for a trivial prepared entry', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-prepared-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\Prepared' . $suffix;

    try {
        $definitions = (new ContainerBuilder())->compileFactories(
            [AuditTrivialEntry::class, AuditNoConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );

        $trivial = $definitions[AuditTrivialEntry::class];
        $noConstructor = $definitions[AuditNoConstructorEntry::class];
        $trivialCode = file_get_contents($directory . '/' . $trivial->file);
        $noConstructorCode = file_get_contents($directory . '/' . $noConstructor->file);

        expect($trivialCode)->toBeString()
            ->and($trivialCode)->toContain('return new \\' . AuditTrivialEntry::class . '();')
            ->and($noConstructorCode)->toBeString()
            ->and($noConstructorCode)->toContain('$this->objects->create(\\' . AuditNoConstructorEntry::class . '::class, $params)');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('AOT specializes plain autowire constructors while runtime overrides keep the generic path', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-fast-constructor-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\FastConstructor' . $suffix;

    try {
        $compiler = new ContainerBuilder();
        $definitions = $compiler->compileFactories(
            [AuditFastConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );
        $definition = $definitions[AuditFastConstructorEntry::class];
        $code = file_get_contents($directory . '/' . $definition->file);

        expect($code)->toBeString()
            ->and($code)->toContain('$this->container->has(\\' . AuditFastDependency::class . '::class)')
            ->and($code)->toContain('$this->container->get(\\' . AuditFastDependency::class . '::class)')
            ->and($code)->toContain('return new \\' . AuditFastConstructorEntry::class . '($dependency0);')
            ->and($code)->toContain('$this->objects->create(\\' . AuditFastConstructorEntry::class . '::class, $params)')
            ->and($code)->toContain('FAST_PATHS');

        $production = compiledAuditContainer($definitions, $directory);
        $default = $production->make(AuditFastConstructorEntry::class);
        $override = $production->make(AuditFastConstructorEntry::class, ['number' => 42]);

        expect($default->dependency)->toBeInstanceOf(AuditFastDependency::class)
            ->and($default->number)->toBe(1)
            ->and($default->name)->toBe('default')
            ->and($override->dependency)->toBeInstanceOf(AuditFastDependency::class)
            ->and($override->number)->toBe(42)
            ->and($override->name)->toBe('default');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('a custom convention resolver disables the plain constructor AOT fast path', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-custom-constructor-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\CustomConstructor' . $suffix;

    try {
        $builder = (new ContainerBuilder())
            ->addParameterResolver(new AuditNumberConventionResolver(), 250);
        $definitions = $builder->compileFactories(
            [AuditFastConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );
        $definition = $definitions[AuditFastConstructorEntry::class];
        $code = file_get_contents($directory . '/' . $definition->file);

        expect($code)->toBeString()
            ->and($code)->not->toContain('return new \\' . AuditFastConstructorEntry::class . '($dependency0);')
            ->and($code)->toContain('$this->objects->create(\\' . AuditFastConstructorEntry::class . '::class, $params)');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('unsupported by-reference and variadic constructor shapes never use the AOT fast path', function (): void {
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
                ->and($code)->not->toContain('return new \\' . $entry . '($dependency0);')
                ->and($code)->toContain('$this->objects->create(\\' . $entry . '::class, $params)');
        }
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
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
