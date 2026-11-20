<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SourceAttributeNativeParity;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionFunction;

final class UntypedEnvironmentProperty
{
    /** @var mixed */
    #[Env('RAW_VALUE')]
    public $value;
}

final class UnionEnvironmentProperty
{
    #[Env('RAW_VALUE')]
    public string|int $value;
}

test('Env preserves the raw environment value for untyped and union targets', function (bool $property, bool $union): void {
    $environment = new Environment(['RAW_VALUE' => '008']);
    $container = (new ContainerBuilder(new Config([], $environment)))->build();
    $callable = $union
        ? static fn(#[Env('RAW_VALUE')] string|int $value): string|int => $value
        : static fn(#[Env('RAW_VALUE')] $value): mixed => $value;

    foreach ([1, 2] as $_attempt) {
        $value = $property
            ? $container->make($union ? UnionEnvironmentProperty::class : UntypedEnvironmentProperty::class)->value
            : $container->call($callable);

        expect($value)->toBe('008')
            ->and($value)->toBe($environment->get('RAW_VALUE'));
    }
})->with(['parameter' => false, 'property' => true])
    ->with(['untyped' => false, 'union' => true]);

final class NativeProxyState
{
    public static int $arguments = 0;
    public static int $conversions = 0;
    public static int $products = 0;
}

final class NativeProxyProduct
{
    public string $value = 'ready';

    public function __construct()
    {
        ++NativeProxyState::$products;
    }
}

final class NativeProxyClassName
{
    public function __construct()
    {
        ++NativeProxyState::$arguments;
    }

    public function __toString(): string
    {
        ++NativeProxyState::$conversions;
        return NativeProxyProduct::class;
    }
}

test('Proxy parameter resolution evaluates its native class-name argument once per invocation', function (): void {
    $callable = eval(sprintf(
        'declare(strict_types=0); namespace %s; return static fn(#[\Componenta\DI\Attribute\Proxy(new NativeProxyClassName())] NativeProxyProduct $value): NativeProxyProduct => $value;',
        __NAMESPACE__,
    ));
    if (!$callable instanceof Closure) {
        throw new LogicException('Expected the native Proxy argument fixture.');
    }
    NativeProxyState::$arguments = NativeProxyState::$conversions = NativeProxyState::$products = 0;
    $reflection = new ReflectionFunction($callable);
    $native = $reflection->getParameters()[0]->getAttributes(Proxy::class)[0]->newInstance();

    expect($native->class)->toBe(NativeProxyProduct::class)
        ->and(NativeProxyState::$arguments)->toBe(1)
        ->and(NativeProxyState::$conversions)->toBe(1);

    NativeProxyState::$arguments = NativeProxyState::$conversions = 0;
    $container = (new ContainerBuilder())->build();
    foreach ([1, 2] as $attempt) {
        $product = $container->call($callable);
        if (!$product instanceof NativeProxyProduct) {
            throw new LogicException('Expected the declared proxy product.');
        }

        expect(NativeProxyState::$arguments)->toBe($attempt)
            ->and(NativeProxyState::$conversions)->toBe($attempt)
            ->and(NativeProxyState::$products)->toBe($attempt - 1)
            ->and(new ReflectionClass(NativeProxyProduct::class)->isUninitializedLazyObject($product))->toBeTrue()
            ->and($product->value)->toBe('ready')
            ->and(NativeProxyState::$products)->toBe($attempt);
    }
});
