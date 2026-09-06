<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Tests\Support\ContainerBuilder;

class ContainerLazyObjectTarget
{
    public function __construct(public string $value) {}

    public function read(): string
    {
        return $this->value;
    }
}

test('container makeLazy defers and performs one public ghost initialization', function (): void {
    $initializations = 0;
    $container = (new ContainerBuilder())->build();

    $lazy = $container->makeLazy(
        ContainerLazyObjectTarget::class,
        static function (ContainerLazyObjectTarget $target) use (&$initializations): void {
            ++$initializations;
            $target->__construct('ghost');
        },
    );

    expect($lazy)->toBeInstanceOf(ContainerLazyObjectTarget::class)
        ->and($initializations)->toBe(0)
        ->and($lazy->read())->toBe('ghost')
        ->and($lazy->read())->toBe('ghost')
        ->and($initializations)->toBe(1);
});

test('container makeProxy defers and reuses one public proxy backing object', function (): void {
    $factoryCalls = 0;
    $backing = new ContainerLazyObjectTarget('proxy');
    $container = (new ContainerBuilder())->build();

    $proxy = $container->makeProxy(
        ContainerLazyObjectTarget::class,
        static function (ContainerLazyObjectTarget $target) use (&$factoryCalls, $backing): ContainerLazyObjectTarget {
            ++$factoryCalls;
            return $backing;
        },
    );

    expect($proxy)->toBeInstanceOf(ContainerLazyObjectTarget::class)
        ->and($factoryCalls)->toBe(0)
        ->and($proxy->read())->toBe('proxy')
        ->and($proxy->read())->toBe('proxy')
        ->and($factoryCalls)->toBe(1);

    $backing->value = 'updated through backing';

    expect($proxy->read())->toBe('updated through backing');

    $proxy->value = 'updated through proxy';

    expect($backing->read())->toBe('updated through proxy')
        ->and($factoryCalls)->toBe(1);
});
