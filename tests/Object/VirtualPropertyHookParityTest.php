<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\VirtualPropertyHookParity;

use Componenta\DI\Attribute\Inject;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;

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

test('Inject passes a dependency once to virtual and write-only property set hooks', function (): void {
    $dependency = new VirtualHookDependency();
    $container = (new ContainerBuilder())
        ->addService(VirtualHookDependency::class, $dependency)
        ->build();

    $virtual = $container->make(VirtualHookTarget::class);
    Assert::assertInstanceOf(VirtualHookTarget::class, $virtual);
    $writeOnly = $container->make(WriteOnlyHookTarget::class);
    Assert::assertInstanceOf(WriteOnlyHookTarget::class, $writeOnly);

    expect($virtual->dependency)->toBe($dependency)
        ->and($virtual->writes)->toBe(1)
        ->and($writeOnly->injected())->toBe($dependency)
        ->and($writeOnly->writes)->toBe(1);
});
