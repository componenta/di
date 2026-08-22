<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final readonly class VirtualHookDependency {}

final class VirtualHookTarget
{
    private ?VirtualHookDependency $captured = null;

    public int $writes = 0;

    #[Inject]
    public VirtualHookDependency $dependency {
        get => $this->captured ?? throw new \LogicException('Dependency is not initialized.');
        set(VirtualHookDependency $value) {
            ++$this->writes;
            $this->captured = $value;
        }
    }
}

final class WriteOnlyHookTarget
{
    private ?VirtualHookDependency $captured = null;

    public int $writes = 0;

    #[Inject]
    public VirtualHookDependency $dependency {
        set(VirtualHookDependency $value) {
            ++$this->writes;
            $this->captured = $value;
        }
    }

    public function injected(): ?VirtualHookDependency
    {
        return $this->captured;
    }
}

function cleanupVirtualPropertyHookParityDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

test('virtual and write-only property set hooks behave identically in development and AOT', function (): void {
    $directory = sys_get_temp_dir()
        . '/componenta-di-virtual-property-hooks-'
        . bin2hex(random_bytes(5));
    $dependency = new VirtualHookDependency();
    $builder = (new ContainerBuilder())
        ->addService(VirtualHookDependency::class, $dependency);
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories(
            [VirtualHookTarget::class, WriteOnlyHookTarget::class],
            $directory,
        );
        $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );
        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();

        foreach ([$development, $production] as $container) {
            $virtual = $container->make(VirtualHookTarget::class);
            $writeOnly = $container->make(WriteOnlyHookTarget::class);

            expect($virtual->dependency)->toBe($dependency)
                ->and($virtual->writes)->toBe(1)
                ->and($writeOnly->injected())->toBe($dependency)
                ->and($writeOnly->writes)->toBe(1);
        }
    } finally {
        cleanupVirtualPropertyHookParityDirectory($directory);
    }
});
