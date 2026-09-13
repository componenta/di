<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

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
    public AuditFastDependency $captured;

    public function __construct(AuditFastDependency &$dependency)
    {
        $this->captured = $dependency;
    }
}

final class AuditVariadicConstructorEntry
{
    /** @var array<array-key,AuditFastDependency> */
    public array $captured;

    public function __construct(AuditFastDependency ...$dependencies)
    {
        $this->captured = $dependencies;
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

test('plain autowiring applies constructor defaults and explicit overrides', function (): void {
    $container = (new ContainerBuilder())->build();

    $defaults = $container->make(AuditFastConstructorEntry::class);
    $overrides = $container->make(AuditFastConstructorEntry::class, ['number' => 42]);

    expect([$defaults->number, $defaults->name])->toBe([1, 'default'])
        ->and([$overrides->number, $overrides->name])->toBe([42, 'default'])
        ->and($defaults->dependency)->toBeInstanceOf(AuditFastDependency::class)
        ->and($overrides->dependency)->toBeInstanceOf(AuditFastDependency::class);
});

test('by-reference constructors remain unsupported while variadics accept explicit dependencies', function (): void {
    $container = (new ContainerBuilder())->build();
    $dependency = new AuditFastDependency();

    expect(fn() => $container->make(AuditByReferenceConstructorEntry::class))->toThrow(ResolutionException::class)
        ->and($container->make(AuditVariadicConstructorEntry::class)->captured)->toBe([])
        ->and($container->make(AuditVariadicConstructorEntry::class, ['dependencies' => [$dependency]])->captured)->toBe([$dependency]);
});
