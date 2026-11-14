<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableInvocationSnapshot;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Target
{
    public function __construct(public string $label) {}

    public function first(#[EntryId('argument-loader')] object $dependency): string
    {
        return $this->label . ':first';
    }

    public function alternate(#[EntryId('argument-loader')] object $dependency): string
    {
        return $this->label . ':alternate';
    }
}

test('callable invocation retains its selected owner and method while resolving dependencies', function (
    bool $service,
    bool $warm,
): void {
    $original = new Target('original');
    $owner = $original;
    $method = 'first';
    $callable = [&$owner, &$method];
    $container = (new ContainerBuilder())
        ->addService('selected-callable', $callable)
        ->addFactory('argument-loader', static function () use (&$owner, &$method, $original): object {
            $original->label = 'updated business state';
            $owner = new Target('replacement');
            $method = 'alternate';
            return new \stdClass();
        })
        ->build();
    $input = $service ? 'selected-callable' : $callable;
    if ($warm) {
        expect($container->call($input, ['dependency' => new \stdClass()]))->toBe('original:first');
    }

    expect($container->call($input))->toBe('updated business state:first')
        ->and($owner->label)->toBe('replacement')
        ->and($method)->toBe('alternate')
        ->and($container->call($input))->toBe('replacement:alternate');
})->with(['explicit array' => false, 'service callable' => true])
    ->with(['cold plan' => false, 'warm plan' => true]);

final class StaticTarget
{
    public static function first(): string
    {
        return 'first';
    }

    public static function alternate(): string
    {
        return 'alternate';
    }
}

test('explicit callable routing is captured before callable validation can autoload application code', function (): void {
    $requested = __NAMESPACE__ . '\\AutoloadedTarget_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'first';
    $container = (new ContainerBuilder())->build();
    $autoload = static function (string $class) use ($requested, &$owner, &$method): void {
        if ($class === $requested) {
            $owner = StaticTarget::class;
            $method = 'alternate';
            class_alias(StaticTarget::class, $class);
        }
    };
    spl_autoload_register($autoload);

    try {
        expect($container->call([&$owner, &$method]))->toBe('first')
            ->and($owner)->toBe(StaticTarget::class)
            ->and($method)->toBe('alternate');
    } finally {
        spl_autoload_unregister($autoload);
    }
});
