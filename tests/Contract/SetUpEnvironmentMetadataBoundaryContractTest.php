<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SetUpEnvironmentMetadataBoundary;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Effects
{
    public static int $calls = 0;
}

#[SetUp('initialize', ['unused' => new Env('UNCONSUMED_SETUP_VALUE')])]
final class MethodTarget
{
    public int $arguments = -1;

    public function initialize(): void
    {
        ++Effects::$calls;
        $this->arguments = func_num_args();
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CallbackSetup extends SetUp
{
    public function __construct()
    {
        parent::__construct(static function (CallbackTarget $instance): void {
            ++Effects::$calls;
            $instance->arguments = func_num_args();
        }, ['unused' => new Env('UNCONSUMED_SETUP_VALUE')]);
    }
}

#[CallbackSetup]
final class CallbackTarget
{
    public int $arguments = -1;
}

test('setup accepts Env descriptors without a matching declared parameter', function (bool $callback): void {
    Effects::$calls = 0;
    $container = (new ContainerBuilder(new Config([], new Environment([
        'UNCONSUMED_SETUP_VALUE' => '008',
    ]))))->build();
    $class = $callback ? CallbackTarget::class : MethodTarget::class;

    foreach ([1, 2] as $attempt) {
        $entry = $container->make($class);

        expect($entry)->toBeInstanceOf($class)
            ->and($entry->arguments)->toBe($callback ? 1 : 0)
            ->and(Effects::$calls)->toBe($attempt);
    }
})->with(['method' => false, 'callback' => true]);

test('setup validates an unused required Env descriptor before invoking its hook', function (bool $callback): void {
    Effects::$calls = 0;
    $container = (new ContainerBuilder(new Config([], new Environment([]))))->build();
    $class = $callback ? CallbackTarget::class : MethodTarget::class;

    expect(fn() => $container->make($class))
        ->toThrow(ResolutionException::class, 'Cannot unwrap #[SetUp] param "unused" (#[Env]): environment variable "UNCONSUMED_SETUP_VALUE" is not defined.')
        ->and(Effects::$calls)->toBe(0);
})->with(['method' => false, 'callback' => true]);
