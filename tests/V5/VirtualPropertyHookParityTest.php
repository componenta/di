<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Tests\Support\ContainerBuilder;

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

test('virtual and write-only property set hooks behave identically for every container build', function (): void {
    $dependency = new VirtualHookDependency();
    $containers = [
        (new ContainerBuilder())
            ->addService(VirtualHookDependency::class, $dependency)
            ->build(),
        (new ContainerBuilder())
            ->addService(VirtualHookDependency::class, $dependency)
            ->build(),
    ];

    foreach ($containers as $container) {
        $virtual = $container->make(VirtualHookTarget::class);
        $writeOnly = $container->make(WriteOnlyHookTarget::class);

        expect($virtual->dependency)->toBe($dependency)
            ->and($virtual->writes)->toBe(1)
            ->and($writeOnly->injected())->toBe($dependency)
            ->and($writeOnly->writes)->toBe(1);
    }
});
