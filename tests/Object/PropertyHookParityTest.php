<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\PropertyHookParity;

use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;

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
    public int $writes = 0;

    #[Init('strtoupper', ['hooked'])]
    public string $value {
        set(string $value) {
            ++$this->writes;
            $this->captured = $value;
        }
    }
}

test('Inject and Init pass resolved values to their property set hooks once', function (): void {
    $dependency = new HookInjectedDependency();
    $container = (new ContainerBuilder())
        ->addService(HookInjectedDependency::class, $dependency)
        ->build();

    $injected = $container->make(HookInjectedTarget::class);
    Assert::assertInstanceOf(HookInjectedTarget::class, $injected);
    $initialized = $container->make(HookInitTarget::class);
    Assert::assertInstanceOf(HookInitTarget::class, $initialized);

    expect($injected->dependency)->toBe($dependency)
        ->and($injected->writes)->toBe(1)
        ->and($initialized->captured)->toBe('HOOKED')
        ->and($initialized->writes)->toBe(1);
});
