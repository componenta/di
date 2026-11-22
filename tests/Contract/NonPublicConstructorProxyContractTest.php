<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\NonPublicConstructorProxy;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;

use function Componenta\DI\Tests\Support\fixtureInstance;

final class Events
{
    /** @var list<string> */
    public static array $values = [];
}

abstract class Product
{
    use UnsupportedTrait;

    public string $value;

    abstract public static function create(): self;
}

final class PrivateProduct extends Product
{
    private function __construct()
    {
        Events::$values[] = 'private constructor';
        $this->value = 'private product';
    }

    public static function create(): self
    {
        return new self();
    }
}

final class ProtectedProduct extends Product
{
    protected function __construct()
    {
        Events::$values[] = 'protected constructor';
        $this->value = 'protected product';
    }

    public static function create(): self
    {
        return new self();
    }
}

final class PrivateConsumer
{
    #[Make('nonpublic.proxy.product'), Proxy]
    public PrivateProduct $product;
}

final class ProtectedConsumer
{
    #[Make('nonpublic.proxy.product'), Proxy]
    public ProtectedProduct $product;
}

function privateParameter(#[Make('nonpublic.proxy.product'), Proxy] PrivateProduct $product): PrivateProduct
{
    return $product;
}

function protectedParameter(#[Make('nonpublic.proxy.product'), Proxy] ProtectedProduct $product): ProtectedProduct
{
    return $product;
}

test('proxy injection defers an application factory that owns a nonpublic constructor', function (string $class, string $consumer, string $callable, string $visibility, bool $property): void {
    /** @var class-string<Product> $class */
    /** @var class-string<PrivateConsumer|ProtectedConsumer> $consumer */
    Events::$values = [];
    $calls = 0;
    $backing = null;
    $container = (new ContainerBuilder())
        ->addFactory('nonpublic.proxy.product', static function () use ($class, &$calls, &$backing): Product {
            ++$calls;
            return $backing = $class::create();
        })
        ->build();

    $proxy = $property
        ? fixtureInstance($container->make($consumer), $consumer)->product
        : $container->call($callable);
    if (!$proxy instanceof Product) {
        throw new LogicException('Expected the declared product type.');
    }
    $reflection = new ReflectionClass($class);

    expect($reflection->isUninitializedLazyObject($proxy))->toBeTrue()
        ->and($calls)->toBe(0)
        ->and($backing)->toBeNull()
        ->and(Events::$values)->toBe([])
        ->and($proxy->value)->toBe($visibility . ' product')
        ->and($proxy->value)->toBe($visibility . ' product')
        ->and($calls)->toBe(1)
        ->and(Events::$values)->toBe([$visibility . ' constructor'])
        ->and($reflection->initializeLazyObject($proxy))->toBe($backing)
        ->and($proxy)->not->toBe($backing);
})->with([
    'private' => [PrivateProduct::class, PrivateConsumer::class, __NAMESPACE__ . '\\privateParameter', 'private'],
    'protected' => [ProtectedProduct::class, ProtectedConsumer::class, __NAMESPACE__ . '\\protectedParameter', 'protected'],
])->with(['property' => true, 'parameter' => false]);

#[NoConstructor, SetUp('initialize')]
final class SkippedPrivateProduct
{
    public string $value = 'raw';

    private function __construct()
    {
        Events::$values[] = 'disabled constructor';
    }

    public function initialize(): void
    {
        Events::$values[] = 'setup';
        $this->value = 'initialized';
    }
}

final class SkippedPrivateConsumer
{
    #[Proxy]
    public SkippedPrivateProduct $product;
}

function skippedPrivateParameter(#[Proxy] SkippedPrivateProduct $product): SkippedPrivateProduct
{
    return $product;
}

test('proxy injection preserves the NoConstructor policy accepted by ordinary reflection creation', function (bool $property): void {
    Events::$values = [];
    $container = (new ContainerBuilder())->build();
    $eager = $container->make(SkippedPrivateProduct::class);
    Assert::assertInstanceOf(SkippedPrivateProduct::class, $eager);
    expect($eager->value)->toBe('initialized')
        ->and(Events::$values)->toBe(['setup']);
    Events::$values = [];

    $proxy = $property
        ? fixtureInstance($container->make(SkippedPrivateConsumer::class), SkippedPrivateConsumer::class)->product
        : $container->call(__NAMESPACE__ . '\\skippedPrivateParameter');
    if (!$proxy instanceof SkippedPrivateProduct) {
        throw new LogicException('Expected the constructor-policy target.');
    }

    expect(new ReflectionClass(SkippedPrivateProduct::class)->isUninitializedLazyObject($proxy))->toBeTrue()
        ->and(Events::$values)->toBe([])
        ->and($proxy->value)->toBe('initialized')
        ->and($proxy->value)->toBe('initialized')
        ->and(Events::$values)->toBe(['setup']);
})->with(['property' => true, 'parameter' => false]);

interface UnsupportedInterface {}

trait UnsupportedTrait {}

abstract class UnsupportedAbstract {}

enum UnsupportedEnum
{
    case Value;
}

final class InterfaceConsumer
{
    #[Make('nonpublic.proxy.product'), Proxy(UnsupportedInterface::class)]
    public object $product;
}

final class TraitConsumer
{
    #[Make('nonpublic.proxy.product'), Proxy(UnsupportedTrait::class)]
    public object $product;
}

final class AbstractConsumer
{
    #[Make('nonpublic.proxy.product'), Proxy(UnsupportedAbstract::class)]
    public object $product;
}

final class EnumConsumer
{
    #[Make('nonpublic.proxy.product'), Proxy(UnsupportedEnum::class)]
    public object $product;
}

test('proxy injection still rejects nonconcrete class kinds before invoking an application factory', function (string $consumer): void {
    /** @var class-string $consumer */
    Events::$values = [];
    $calls = 0;
    $container = (new ContainerBuilder())
        ->addFactory('nonpublic.proxy.product', static function () use (&$calls): Product {
            ++$calls;
            return PrivateProduct::create();
        })
        ->build();

    expect(fn() => $container->make($consumer))->toThrow(ResolutionException::class, 'must be concrete')
        ->and($calls)->toBe(0)
        ->and(Events::$values)->toBe([]);
})->with([InterfaceConsumer::class, TraitConsumer::class, AbstractConsumer::class, EnumConsumer::class]);

final class PropertylessPrivateProduct
{
    private function __construct()
    {
        Events::$values[] = 'propertyless constructor';
    }

    public static function create(): self
    {
        return new self();
    }

    public function __destruct()
    {
        Events::$values[] = 'propertyless destructor';
    }
}

final class PropertylessPrivateConsumer
{
    #[Make('propertyless.private.product'), Proxy]
    public PropertylessPrivateProduct $product;
}

function propertylessPrivateParameter(#[Make('propertyless.private.product'), Proxy] PropertylessPrivateProduct $product): PropertylessPrivateProduct
{
    return $product;
}

test('a nonpublic constructor does not bypass default native propertyless rejection', function (bool $property): void {
    Events::$values = [];
    $calls = 0;
    $container = (new ContainerBuilder())
        ->addFactory('propertyless.private.product', static function () use (&$calls): PropertylessPrivateProduct {
            ++$calls;
            return PropertylessPrivateProduct::create();
        })
        ->build();
    $failure = null;

    try {
        $property
            ? $container->make(PropertylessPrivateConsumer::class)
            : $container->call(__NAMESPACE__ . '\\propertylessPrivateParameter');
    } catch (ResolutionException $error) {
        $failure = $error;
    }

    expect($failure)->toBeInstanceOf(ResolutionException::class)
        ->and($failure?->getMessage())->toContain('no backed instance properties')
        ->and($failure?->serviceId)->toBe(PropertylessPrivateProduct::class)
        ->and($failure?->getPrevious())->toBeNull()
        ->and($calls)->toBe(0)
        ->and(Events::$values)->toBe([]);
})->with(['property' => true, 'parameter' => false]);
