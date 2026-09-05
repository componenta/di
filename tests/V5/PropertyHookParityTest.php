<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Tests\Support\ContainerBuilder;

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

test('Inject and Init execute PHP property set hooks identically for every container build', function (): void {
    $dependency = new HookInjectedDependency();
    $containers = [
        (new ContainerBuilder())
            ->addService(HookInjectedDependency::class, $dependency)
            ->build(),
        (new ContainerBuilder())
            ->addService(HookInjectedDependency::class, $dependency)
            ->build(),
    ];

    foreach ($containers as $container) {
        $injected = $container->make(HookInjectedTarget::class);
        $initialized = $container->make(HookInitTarget::class);

        expect($injected->dependency)->toBe($dependency)
            ->and($injected->writes)->toBe(1)
            ->and($initialized->captured)->toBe('HOOKED');
    }
});
