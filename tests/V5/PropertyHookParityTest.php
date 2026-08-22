<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final readonly class HookInjectedDependency {}

final class HookInjectedTarget
{
    public int $writes = 0;

    #[Inject]
    public HookInjectedDependency $dependency {
        set(HookInjectedDependency $value) {
            ++$this->writes;
            $this->dependency = $value;
        }
    }
}

final class HookInitTarget
{
    public string $captured = '';

    #[Init('strtoupper', ['hooked'])]
    public string $value {
        set(string $value) {
            $this->captured = $value;
        }
    }
}

function cleanupPropertyHookParityDirectory(string $directory): void
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

test('Inject and Init execute PHP property set hooks identically in development and AOT', function (): void {
    $directory = sys_get_temp_dir()
        . '/componenta-di-property-hooks-'
        . bin2hex(random_bytes(5));
    $dependency = new HookInjectedDependency();
    $builder = (new ContainerBuilder())
        ->addService(HookInjectedDependency::class, $dependency);
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories(
            [HookInjectedTarget::class, HookInitTarget::class],
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
            $injected = $container->make(HookInjectedTarget::class);
            $initialized = $container->make(HookInitTarget::class);

            expect($injected->dependency)->toBe($dependency)
                ->and($injected->writes)->toBe(1)
                ->and($initialized->captured)->toBe('HOOKED');
        }
    } finally {
        cleanupPropertyHookParityDirectory($directory);
    }
});
