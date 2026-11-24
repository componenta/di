<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Lazy\PropertylessLazyLifecycleContract;

use Closure;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class PropertylessLifecycleEvents
{
    /** @var array<class-string, list<string>> */
    public static array $byClass = [];
}

abstract class PropertylessLifecycleTarget
{
    public function __construct()
    {
        PropertylessLifecycleEvents::$byClass[static::class][] = 'constructor';
    }

    public function initialize(): void
    {
        PropertylessLifecycleEvents::$byClass[static::class][] = 'setup';
    }

    public function __destruct()
    {
        PropertylessLifecycleEvents::$byClass[static::class][] = 'destructor';
    }
}

#[Lazy, SetUp('initialize')]
final class PropertylessLazyLifecycleTarget extends PropertylessLifecycleTarget {}

#[Proxy, SetUp('initialize')]
final class PropertylessProxyLifecycleTarget extends PropertylessLifecycleTarget {}

#[NoConstructor, Lazy, SetUp('initialize')]
final class PropertylessSkippedLazyLifecycleTarget extends PropertylessLifecycleTarget {}

#[NoConstructor, Proxy, SetUp('initialize')]
final class PropertylessSkippedProxyLifecycleTarget extends PropertylessLifecycleTarget {}

/** @param callable(): mixed $resolve */
function propertylessLifecycleRejection(callable $resolve): ResolutionException
{
    try {
        $resolve();
    } catch (ResolutionException $exception) {
        expect($exception->getMessage())->toContain('no backed instance properties');
        return $exception;
    }

    throw new \LogicException('Expected native laziness without a backed instance property to be rejected.');
}

test('propertyless lazy lifecycle rejection publishes no shared value and permits an explicit eager definition', function (string $class): void {
    if (!is_a($class, PropertylessLifecycleTarget::class, true)) {
        throw new \LogicException('Expected a propertyless lifecycle target.');
    }
    PropertylessLifecycleEvents::$byClass[$class] = [];
    $container = (new ContainerBuilder())->build();

    try {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $failure = propertylessLifecycleRejection(fn() => $container->get($class));

            expect($failure->serviceId)->toBe($class)
                ->and($failure->getPrevious())->toBeNull()
                ->and(PropertylessLifecycleEvents::$byClass[$class])->toBe([]);
        }

        $container->set($class, ClassDefinition::create($class));
        $target = $container->get($class);

        expect($target)->toBeInstanceOf($class)
            ->and($container->get($class))->toBe($target)
            ->and(PropertylessLifecycleEvents::$byClass[$class])->toBe(['constructor']);
    } finally {
        unset($failure, $target, $container);
        gc_collect_cycles();
    }

    expect(PropertylessLifecycleEvents::$byClass[$class])->toBe(['constructor', 'destructor']);
})->with([
    'lazy with setup' => [PropertylessLazyLifecycleTarget::class],
    'proxy with setup' => [PropertylessProxyLifecycleTarget::class],
    'disabled constructor and lazy setup' => [PropertylessSkippedLazyLifecycleTarget::class],
    'disabled constructor and proxy setup' => [PropertylessSkippedProxyLifecycleTarget::class],
]);

#[Proxy, SetUp('initialize')]
final class PropertylessMakeProduct extends PropertylessLifecycleTarget {}

final class PropertylessMakeConsumer
{
    #[Make('propertyless.lifecycle.product'), Proxy]
    public PropertylessMakeProduct $product;
}

function propertylessMakeParameter(
    #[Make('propertyless.lifecycle.product'), Proxy] PropertylessMakeProduct $product,
): PropertylessMakeProduct {
    return $product;
}

test('Make with Proxy rejects a propertyless product before its factory runs and preserves the ordinary factory route', function (bool $property): void {
    PropertylessLifecycleEvents::$byClass[PropertylessMakeProduct::class] = [];
    $factoryCalls = 0;
    $container = (new ContainerBuilder())
        ->addFactory('propertyless.lifecycle.product', static function () use (&$factoryCalls): PropertylessMakeProduct {
            ++$factoryCalls;
            return new PropertylessMakeProduct();
        })
        ->build();

    try {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $failure = propertylessLifecycleRejection($property
                ? fn() => $container->get(PropertylessMakeConsumer::class)
                : fn() => $container->call(__NAMESPACE__ . '\\propertylessMakeParameter'));

            expect($failure->serviceId)->toBe(PropertylessMakeProduct::class)
                ->and($failure->getPrevious())->toBeNull()
                ->and($factoryCalls)->toBe(0)
                ->and(PropertylessLifecycleEvents::$byClass[PropertylessMakeProduct::class])->toBe([]);
        }

        $product = $container->get('propertyless.lifecycle.product');

        expect($product)->toBeInstanceOf(PropertylessMakeProduct::class)
            ->and($container->get('propertyless.lifecycle.product'))->toBe($product)
            ->and($factoryCalls)->toBe(1)
            ->and(PropertylessLifecycleEvents::$byClass[PropertylessMakeProduct::class])->toBe(['constructor']);
    } finally {
        unset($failure, $product, $container);
        gc_collect_cycles();
    }

    expect(PropertylessLifecycleEvents::$byClass[PropertylessMakeProduct::class])->toBe(['constructor', 'destructor']);
})->with(['property' => true, 'parameter' => false]);

abstract class BackedLazyLifecycleTarget extends PropertylessLifecycleTarget
{
    public string $state = 'raw';

    public function __construct()
    {
        parent::__construct();
        $this->state = 'constructed';
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->state = 'initialized';
    }
}

#[Lazy, SetUp('initialize')]
final class BackedGhostLifecycleTarget extends BackedLazyLifecycleTarget {}

#[Proxy, SetUp('initialize')]
final class BackedProxyLifecycleTarget extends BackedLazyLifecycleTarget {}

#[NoConstructor, Lazy, SetUp('initialize')]
final class BackedSkippedGhostLifecycleTarget extends BackedLazyLifecycleTarget {}

#[NoConstructor, Proxy, SetUp('initialize')]
final class BackedSkippedProxyLifecycleTarget extends BackedLazyLifecycleTarget {}

test('backed lazy lifecycle still defers setup and respects the constructor policy', function (string $class, bool $constructor): void {
    if (!is_a($class, BackedLazyLifecycleTarget::class, true)) {
        throw new \LogicException('Expected a backed lifecycle target.');
    }
    PropertylessLifecycleEvents::$byClass[$class] = [];
    $container = (new ContainerBuilder())->build();
    $expected = $constructor ? ['constructor', 'setup'] : ['setup'];

    try {
        $target = $container->get($class);
        if (!$target instanceof BackedLazyLifecycleTarget) {
            throw new \LogicException('Expected the declared backed lifecycle target.');
        }

        expect(PropertylessLifecycleEvents::$byClass[$class])->toBe([])
            ->and($container->get($class))->toBe($target)
            ->and($target->state)->toBe('initialized')
            ->and($target->state)->toBe('initialized')
            ->and(PropertylessLifecycleEvents::$byClass[$class])->toBe($expected);
    } finally {
        unset($target, $container);
        gc_collect_cycles();
    }

    expect(PropertylessLifecycleEvents::$byClass[$class])->toBe([...$expected, 'destructor']);
})->with([
    'lazy with setup' => [BackedGhostLifecycleTarget::class, true],
    'proxy with setup' => [BackedProxyLifecycleTarget::class, true],
    'disabled constructor and lazy setup' => [BackedSkippedGhostLifecycleTarget::class, false],
    'disabled constructor and proxy setup' => [BackedSkippedProxyLifecycleTarget::class, false],
]);

final class PropertylessCustomFactoryTarget {}

/** Records delegation; callback execution is controlled by this protocol test. */
final class PropertylessRecordingProxyFactory implements ProxyFactoryInterface
{
    /** @var list<array{string, class-string}> */
    public array $calls = [];
    /** @var (Closure(): void)|null */
    public ?Closure $initializer = null;
    /** @var (Closure(): object)|null */
    public ?Closure $factory = null;

    public function __construct(private readonly PropertylessCustomFactoryTarget $result) {}

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): void $initializer
     * @return T
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        if (!$this->result instanceof $class) {
            throw new \LogicException('Unexpected custom factory target.');
        }
        $this->calls[] = ['lazy', $class];
        $target = $this->result;
        $this->initializer = static function () use ($initializer, $target): void {
            $initializer($target);
        };
        return $target;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): T $factory
     * @return T
     */
    public function makeProxy(string $class, callable $factory): object
    {
        if (!$this->result instanceof $class) {
            throw new \LogicException('Unexpected custom factory target.');
        }
        $this->calls[] = ['proxy', $class];
        $target = $this->result;
        $this->factory = static fn(): object => $factory($target);
        return $target;
    }
}

test('container delegates propertyless targets and callbacks to its supplied proxy factory', function (): void {
    $returned = new PropertylessCustomFactoryTarget();
    $backing = new PropertylessCustomFactoryTarget();
    $factory = new PropertylessRecordingProxyFactory($returned);
    $resolver = new class () implements EntryResolverInterface {
        public function can(string $id): bool
        {
            return false;
        }

        public function resolve(string $id, EntryParameters $params): never
        {
            throw new NotFoundException('This test only requests the supplied proxy factory.');
        }
    };
    $container = new Container($resolver, (new ContainerBuilder())->build(), proxyFactory: $factory);
    $calls = [];

    $ghost = $container->makeLazy(PropertylessCustomFactoryTarget::class, static function (PropertylessCustomFactoryTarget $target) use (&$calls, $returned): void {
        expect($target)->toBe($returned);
        $calls[] = 'initializer';
    });
    $proxy = $container->makeProxy(PropertylessCustomFactoryTarget::class, static function (PropertylessCustomFactoryTarget $target) use (&$calls, $returned, $backing): PropertylessCustomFactoryTarget {
        expect($target)->toBe($returned);
        $calls[] = 'factory';
        return $backing;
    });

    expect($ghost)->toBe($returned)
        ->and($proxy)->toBe($returned)
        ->and($factory->calls)->toBe([
            ['lazy', PropertylessCustomFactoryTarget::class],
            ['proxy', PropertylessCustomFactoryTarget::class],
        ])
        ->and($calls)->toBe([]);
    if ($factory->initializer === null || $factory->factory === null) {
        throw new \LogicException('Expected both callbacks to reach the supplied factory.');
    }

    ($factory->initializer)();
    expect(($factory->factory)())->toBe($backing)
        ->and($calls)->toBe(['initializer', 'factory']);
});
