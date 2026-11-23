<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Container;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;

class ContainerLazyObjectTarget
{
    public function __construct(public string $value) {}

    public function read(): string
    {
        return $this->value;
    }
}

final class ContainerLazyObjectChild extends ContainerLazyObjectTarget {}

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

test('a parent-backed virtual proxy retains the requested child type', function (bool $useContainer): void {
    $factory = $useContainer ? (new ContainerBuilder())->build() : new ProxyFactory();
    $backing = new ContainerLazyObjectTarget('parent backing');
    $receiver = null;
    $create = static function (Container|ProxyFactory $factory) use ($backing, &$receiver): ContainerLazyObjectChild {
        return $factory->makeProxy(
            ContainerLazyObjectChild::class,
            static function (ContainerLazyObjectChild $proxy) use ($backing, &$receiver): ContainerLazyObjectTarget {
                $receiver = $proxy;
                return $backing;
            },
        );
    };

    $proxy = $create($factory);

    expect($receiver)->toBeNull()
        ->and($proxy->read())->toBe('parent backing')
        ->and($receiver)->toBe($proxy);
})->with(['container' => true, 'native adapter' => false]);
