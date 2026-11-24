<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Lazy\ContainerLazyObjectContract;

use Componenta\DI\Container;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;

class ContainerLazyObjectTarget
{
    public function __construct(public string $value) {}

    public function kind(): string
    {
        return 'product';
    }

    public function read(): string
    {
        return $this->value;
    }
}

final class ContainerLazyObjectChild extends ContainerLazyObjectTarget {}

test('container makeLazy defers and performs one public ghost initialization', function (): void {
    $initializations = 0;
    $receiver = null;
    $container = (new ContainerBuilder())->build();

    $lazy = $container->makeLazy(
        ContainerLazyObjectTarget::class,
        static function (ContainerLazyObjectTarget $target) use (&$initializations, &$receiver): void {
            ++$initializations;
            $receiver = $target;
            $target->__construct('ghost');
        },
    );

    expect($lazy)->toBeInstanceOf(ContainerLazyObjectTarget::class)
        ->and($lazy->kind())->toBe('product')
        ->and($initializations)->toBe(0)
        ->and($receiver)->toBeNull()
        ->and($lazy->read())->toBe('ghost')
        ->and($lazy->read())->toBe('ghost')
        ->and($receiver)->toBe($lazy)
        ->and($initializations)->toBe(1);
});

test('container makeProxy defers and reuses one public proxy backing object', function (): void {
    $factoryCalls = 0;
    $receiver = null;
    $backing = new ContainerLazyObjectTarget('proxy');
    $container = (new ContainerBuilder())->build();

    $proxy = $container->makeProxy(
        ContainerLazyObjectTarget::class,
        static function (ContainerLazyObjectTarget $target) use (&$factoryCalls, &$receiver, $backing): ContainerLazyObjectTarget {
            ++$factoryCalls;
            $receiver = $target;
            return $backing;
        },
    );

    expect($proxy)->toBeInstanceOf(ContainerLazyObjectTarget::class)
        ->and($proxy)->not->toBe($backing)
        ->and($proxy->kind())->toBe('product')
        ->and($factoryCalls)->toBe(0)
        ->and($receiver)->toBeNull()
        ->and($proxy->read())->toBe('proxy')
        ->and($proxy->read())->toBe('proxy')
        ->and($receiver)->toBe($proxy)
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
