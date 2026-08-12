<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final readonly class CompiledParityDependencyForTest {}

final readonly class CompiledParitySetUpContextForTest {}

#[SetUp('initialize', ['label' => 'ready'])]
final class CompiledParityEntryForTest
{
    #[Inject]
    public CompiledParityDependencyForTest $property;

    public bool $initialized = false;

    public string $label = '';

    public ?CompiledParitySetUpContextForTest $setupContext = null;

    public function __construct(
        public CompiledParityDependencyForTest $constructor,
        public int $value = 1,
    ) {}

    public function initialize(
        CompiledParityDependencyForTest $dependency,
        CompiledParitySetUpContextForTest $setupContext,
        string $label,
    ): void {
        $this->initialized = $dependency === $this->constructor;
        $this->setupContext = $setupContext;
        $this->label = $label;
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
        $reflectionContext = new CompiledParitySetUpContextForTest();
        $compiledContext = new CompiledParitySetUpContextForTest();

        $expected = $reflection->make(CompiledParityEntryForTest::class, [
            'value' => 19,
            CompiledParitySetUpContextForTest::class => $reflectionContext,
        ]);
        $actual = $compiled->make(CompiledParityEntryForTest::class, [
            'value' => 19,
            CompiledParitySetUpContextForTest::class => $compiledContext,
        ]);

        expect($actual->value)->toBe($expected->value)
            ->and($actual->constructor)->toBeInstanceOf(CompiledParityDependencyForTest::class)
            ->and($actual->property)->toBe($actual->constructor)
            ->and($actual->initialized)->toBeTrue()
            ->and($actual->label)->toBe('ready')
            ->and($expected->label)->toBe('ready')
            ->and($actual->setupContext)->toBe($compiledContext)
            ->and($expected->setupContext)->toBe($reflectionContext)
            ->and($compiled->make(CompiledParityNoConstructorForTest::class)->value)
            ->toBe($reflection->make(CompiledParityNoConstructorForTest::class)->value);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
