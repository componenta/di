<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final readonly class CompiledParityDependencyForTest {}

#[SetUp('initialize')]
final class CompiledParityEntryForTest
{
    #[Inject]
    public CompiledParityDependencyForTest $property;

    public bool $initialized = false;

    public function __construct(
        public CompiledParityDependencyForTest $constructor,
        public int $value = 1,
    ) {}

    public function initialize(CompiledParityDependencyForTest $dependency): void
    {
        $this->initialized = $dependency === $this->constructor;
    }
}

#[NoConstructor]
final class CompiledParityNoConstructorForTest
{
    public int $value = 7;

    private function __construct()
    {
        throw new RuntimeException('Constructor must not run.');
    }
}

it('keeps constructor context, injection, setup and no-constructor behavior equal to reflection', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-factory-parity-' . bin2hex(random_bytes(5));

    try {
        $reflection = (new ContainerBuilder())->build();
        $factories = (new ContainerBuilder())->compileFactories([
            CompiledParityEntryForTest::class,
            CompiledParityNoConstructorForTest::class,
        ], $directory);
        $compiled = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->build();

        $expected = $reflection->make(CompiledParityEntryForTest::class, ['value' => 19]);
        $actual = $compiled->make(CompiledParityEntryForTest::class, ['value' => 19]);

        expect($actual->value)->toBe($expected->value)
            ->and($actual->constructor)->toBeInstanceOf(CompiledParityDependencyForTest::class)
            ->and($actual->property)->toBe($actual->constructor)
            ->and($actual->initialized)->toBeTrue()
            ->and($compiled->make(CompiledParityNoConstructorForTest::class)->value)
            ->toBe($reflection->make(CompiledParityNoConstructorForTest::class)->value);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
