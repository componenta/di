<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\ProxyFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;

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

test('lazy allocation retains the supplied callable route through target autoloading', function (
    bool $container,
    bool $proxy,
    bool $rebindDuringAutoload,
): void {
    $factory = $container ? new ContainerBuilder()->build() : new ProxyFactory();
    $original = new class () {
        public int $calls = 0;
        public string $value = 'original';

        public function initialize(ContainerLazyObjectTarget $target): void
        {
            ++$this->calls;
            $target->value = $this->value;
        }

        public function build(ContainerLazyObjectTarget $target): ContainerLazyObjectTarget
        {
            ++$this->calls;
            return new ContainerLazyObjectTarget($this->value);
        }

        public function unexpected(ContainerLazyObjectTarget $target): never
        {
            throw new LogicException('The caller rebound the supplied method during allocation.');
        }
    };
    $receiver = $original;
    $replacement = clone $original;
    $replacement->value = 'replacement';
    $method = $proxy ? 'build' : 'initialize';
    $callback = [&$receiver, &$method];
    $rebind = static function () use (&$receiver, &$method, $replacement): void {
        $receiver = $replacement;
        $method = 'unexpected';
    };
    /** @var class-string<ContainerLazyObjectTarget> $class */
    $class = __NAMESPACE__ . '\\DeferredLazyCallbackTarget_' . bin2hex(random_bytes(5));
    $loader = static function (string $name) use ($class, $rebindDuringAutoload, $rebind): void {
        if ($name === $class) {
            if ($rebindDuringAutoload) {
                $rebind();
            }
            class_alias(ContainerLazyObjectTarget::class, $class, false);
        }
    };
    spl_autoload_register($loader);
    try {
        $target = $proxy ? $factory->makeProxy($class, $callback) : $factory->makeLazy($class, $callback);
    } finally {
        spl_autoload_unregister($loader);
    }
    if (!$rebindDuringAutoload) {
        $rebind();
    }

    $original->value = 'updated business state';
    $reflection = new ReflectionClass($class);
    expect($reflection->isUninitializedLazyObject($target))->toBeTrue()
        ->and($original->calls)->toBe(0)
        ->and($replacement->calls)->toBe(0)
        ->and($target->read())->toBe('updated business state')
        ->and($original->calls)->toBe(1)
        ->and($replacement->calls)->toBe(0);
})->with(['factory' => false, 'container' => true])
    ->with(['ghost' => false, 'proxy' => true])
    ->with(['rebind during autoload' => true, 'rebind after allocation' => false]);
