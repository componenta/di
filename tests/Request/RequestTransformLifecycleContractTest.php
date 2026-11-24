<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestTransformLifecycleContract;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use WeakReference;

final class State
{
    /** @var list<string> */
    public static array $events = [];
    public static bool $failInitialization = false;
    /** @var WeakReference<Product>|null */
    public static ?WeakReference $transformed = null;
}

class Product
{
    #[Init(__NAMESPACE__ . '\\initialize')]
    public string $ready;

    public function __construct(public readonly string $name)
    {
        State::$events[] = 'constructor';
    }
}

#[Lazy]
final class LazyProduct extends Product {}

#[Proxy]
final class ProxyProduct extends Product {}

function initialize(): string
{
    State::$events[] = 'init';
    if (State::$failInitialization) {
        State::$failInitialization = false;
        throw new RuntimeException('Retry property initialization.');
    }
    return 'ready';
}

function transformProduct(Product $product): Product
{
    State::$events[] = 'transform';
    State::$transformed = WeakReference::create($product);
    return $product;
}

/**
 * @param array<string|int,mixed> $data
 * @return array<string|int,mixed>
 */
function transformArray(array $data, #[EntryId('request.transform.suffix')] string $suffix): array
{
    State::$events[] = 'transform';
    return $data + ['suffix' => $suffix];
}

// Runtime closure constants preserve the PHP 8.4 attribute syntax contract.
define(__NAMESPACE__ . '\\PRODUCT', transformProduct(...));
define(__NAMESPACE__ . '\\DATA', transformArray(...));

function eagerTarget(
    #[MapRequest(sources: [RequestDataSource::Query], map: ['input' => 'name']), Transform(PRODUCT)]
    Product $product,
): Product {
    return $product;
}

function lazyTarget(
    #[MapRequest(sources: [RequestDataSource::Query], map: ['input' => 'name']), Transform(PRODUCT)]
    LazyProduct $product,
): Product {
    return $product;
}

function proxyTarget(
    #[MapRequest(sources: [RequestDataSource::Query], map: ['input' => 'name']), Transform(PRODUCT)]
    ProxyProduct $product,
): Product {
    return $product;
}

beforeEach(function (): void {
    State::$events = [];
    State::$failInitialization = false;
    State::$transformed = null;
});

test('Transform on a mapped array receives the completed mapping and resolves current suffix services', function (): void {
    $container = (new ContainerBuilder())->addService('request.transform.suffix', 'first')->build();
    $target = static fn(
        #[MapRequest(sources: [RequestDataSource::Query], map: ['input' => 'name']), Transform(DATA)]
        array $data,
    ): array => $data;

    foreach (['first input' => 'first service', 'second input' => 'second service'] as $input => $suffix) {
        $container->set('request.transform.suffix', $suffix);
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['input' => $input]);

        expect($container->call($target, [ServerRequestInterface::class => $request]))
            ->toBe(['name' => $input, 'suffix' => $suffix]);
    }

    expect(State::$events)->toBe(['transform', 'transform']);
});

test('Transform receives the mapped DTO without forcing its deferred constructor or Init callback', function (
    string $target,
    bool $deferred,
): void {
    $container = (new ContainerBuilder())->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['input' => 'mapped name']);
    $requestReference = WeakReference::create($request);
    $product = $container->call($target, [ServerRequestInterface::class => $request]);
    unset($request);
    if (!$product instanceof Product) {
        throw new LogicException('Expected a mapped DTO.');
    }

    expect(State::$transformed?->get())->toBe($product)
        ->and(State::$events)->toBe($deferred ? ['transform'] : ['constructor', 'init', 'transform'])
        ->and($product->name)->toBe('mapped name')
        ->and($product->ready)->toBe('ready')
        ->and(State::$events)->toBe($deferred ? ['transform', 'constructor', 'init'] : ['constructor', 'init', 'transform'])
        ->and($requestReference->get())->toBeNull();

    unset($product);
    gc_collect_cycles();

    expect(State::$transformed?->get())->toBeNull();
})->with([
    'eager' => [__NAMESPACE__ . '\\eagerTarget', false],
    'lazy ghost' => [__NAMESPACE__ . '\\lazyTarget', true],
    'virtual proxy' => [__NAMESPACE__ . '\\proxyTarget', true],
]);

test('retrying a deferred mapped DTO does not replay its completed outer Transform', function (string $target): void {
    $container = (new ContainerBuilder())->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['input' => 'mapped name']);
    $requestReference = WeakReference::create($request);
    State::$failInitialization = true;
    $product = $container->call($target, [ServerRequestInterface::class => $request]);
    unset($request);
    if (!$product instanceof Product) {
        throw new LogicException('Expected a deferred mapped DTO.');
    }

    expect(State::$events)->toBe(['transform'])
        ->and(fn(): string => $product->ready)->toThrow(ResolutionException::class, 'Retry property initialization.')
        ->and(State::$events)->toBe(['transform', 'constructor', 'init'])
        ->and($product->ready)->toBe('ready')
        ->and($product->name)->toBe('mapped name')
        ->and(State::$events)->toBe(['transform', 'constructor', 'init', 'constructor', 'init'])
        ->and(State::$transformed?->get())->toBe($product)
        ->and($requestReference->get())->toBeNull();
})->with([__NAMESPACE__ . '\\lazyTarget', __NAMESPACE__ . '\\proxyTarget']);
