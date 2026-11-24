<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Delegator\StaticDelegatorInvalidationContract;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final class StaticDecorator
{
    public static int $calls = 0;

    public static function declared(Product $entry): Product
    {
        ++self::$calls;
        return new Product($entry->value . ':declared');
    }

    protected function hiddenInstance(): never
    {
        throw new LogicException('The inaccessible instance method must not be invoked.');
    }

    protected static function hiddenStatic(): never
    {
        throw new LogicException('The inaccessible static method must not be invoked.');
    }

    /** @param array<array-key, mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): Product
    {
        $entry = $arguments[0] ?? null;
        if (!$entry instanceof Product) {
            throw new LogicException('Expected a product in the native delegator.');
        }
        ++self::$calls;
        return new Product($entry->value . ':' . $method);
    }
}

final class InstanceDecorator
{
    public function __construct(private readonly string $suffix) {}

    public function decorate(Product $entry): Product
    {
        return new Product($entry->value . $this->suffix);
    }
}

final class LateRouteDecorator
{
    public static int $calls = 0;

    public static function fromClass(Product $entry): Product
    {
        ++self::$calls;
        return new Product($entry->value . ':static');
    }

    public function decorate(Product $entry): Product
    {
        return new Product($entry->value . ':instance');
    }
}

test('native static delegators preserve shared results when an unused owner binding changes', function (string $method): void {
    StaticDecorator::$calls = 0;
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $specification = StaticDecorator::class . '::' . $method;
    $container->delegator('product', $specification);
    $original = $container->get('product');
    expect($original)->toEqual(new Product('base:' . $method));

    $container->set(StaticDecorator::class, new \stdClass());

    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->delegator(StaticDecorator::class, static fn(object $entry): object => $entry);
    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->set($specification, static fn(Product $entry): Product => new Product($entry->value . ':replacement'));
    $replacement = $container->get('product');

    expect($replacement)->not->toBe($original)
        ->and($replacement)->toEqual(new Product('base:replacement'))
        ->and($container->get('product'))->toBe($replacement)
        ->and(StaticDecorator::$calls)->toBe(1);
})->with(['declared', 'dynamic', 'hiddenInstance', 'hiddenStatic']);

test('static delegators loaded during registration do not acquire owner dependencies', function (bool $loaded): void {
    StaticDecorator::$calls = 0;
    $class = __NAMESPACE__ . '\\AutoloadedDecorator_' . bin2hex(random_bytes(5));
    if ($loaded) {
        class_alias(StaticDecorator::class, $class);
    }
    $attempts = 0;
    $loader = static function (string $requested) use ($class, &$attempts): void {
        if ($requested === $class && ++$attempts === 2) {
            class_alias(StaticDecorator::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
        $container->delegator('product', $class . '::declared');
        expect(class_exists($class, false))->toBeTrue();
        $original = $container->get('product');
        expect($original)->toEqual(new Product('base:declared'));

        $container->set($class, new \stdClass());

        expect($container->get('product'))->toBe($original)
            ->and(StaticDecorator::$calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['already loaded' => true, 'loaded on second probe' => false]);

test('native static pairs remain independent of both owner bindings and exact string callable services', function (string $method): void {
    StaticDecorator::$calls = 0;
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $specification = [StaticDecorator::class, $method];
    $container->delegator('product', $specification);
    $original = $container->get('product');
    expect($original)->toEqual(new Product('base:' . $method));
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');

    $container->set(StaticDecorator::class, new \stdClass());
    $container->set(StaticDecorator::class . '::' . $method, $replacement);

    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1)
        ->and($container->resolve($specification))->toBe($specification)
        ->and($container->resolve(StaticDecorator::class . '::' . $method))->toBe($replacement);
})->with(['declared', 'dynamic']);

test('late native method arrays retain shared results unless a delegate consumes their owner', function (string $chain): void {
    LateRouteDecorator::$calls = 0;
    $owner = __NAMESPACE__ . '\\LateMethodArrayOwner_' . bin2hex(random_bytes(5));
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $container->delegator('product', [$owner, 'fromClass']);
    if ($chain === 'independent') {
        $container->delegator('product', static fn(Product $entry): Product => $entry);
    } elseif ($chain === 'uses-owner') {
        $container->delegator('product', [$owner, 'decorate']);
    }
    $attempts = 0;
    $loader = static function (string $requested) use ($owner, &$attempts): void {
        if ($requested === $owner && ++$attempts === 3) {
            class_alias(LateRouteDecorator::class, $owner, autoload: false);
        }
    };

    spl_autoload_register($loader);
    try {
        $first = $container->get('product');
        $container->set($owner, new InstanceDecorator(':replacement'));
        $current = $container->get('product');
    } finally {
        spl_autoload_unregister($loader);
    }

    if ($chain === 'uses-owner') {
        expect($first)->toEqual(new Product('base:static:instance'))
            ->and($current)->toEqual(new Product('base:static:replacement'))
            ->and(LateRouteDecorator::$calls)->toBe(2);
    } else {
        expect($first)->toEqual(new Product('base:static'))
            ->and($current)->toBe($first)
            ->and(LateRouteDecorator::$calls)->toBe(1);
    }
})->with([
    'single native delegate' => 'single',
    'independent second delegate' => 'independent',
    'second delegate uses the owner' => 'uses-owner',
]);

test('standalone native method pairs ignore owner replacement unless another delegator uses that owner', function (
    ?string $custom,
    bool $loaded,
): void {
    LateRouteDecorator::$calls = 0;
    $owner = __NAMESPACE__ . '\\StandaloneLateMethodOwner_' . bin2hex(random_bytes(5));
    $executor = new class ($owner) implements CallableExecutorInterface {
        public Container $container;
        public int $resolutions = 0;

        public function __construct(private readonly string $owner) {}

        public function call(mixed $callable, array $params = []): mixed
        {
            throw new LogicException('The delegator invokes the resolved callable directly.');
        }

        public function resolve(mixed $callable): callable
        {
            ++$this->resolutions;
            $service = $this->container->get($this->owner);
            if (!$service instanceof InstanceDecorator) {
                throw new LogicException('Expected the current decorator service.');
            }
            return $service->decorate(...);
        }
    };
    $container = new Container(new class () implements EntryResolverInterface {
        public function can(string $id): bool
        {
            return false;
        }

        public function resolve(string $id, EntryParameters $params): mixed
        {
            throw new LogicException('The test uses registered services.');
        }
    }, $executor, services: ['product' => new Product('base')]);
    $executor->container = $container;
    $container->set($owner, new InstanceDecorator(':first'));
    if ($loaded) {
        class_alias(LateRouteDecorator::class, $owner);
    }
    $container->delegator('product', [$owner, 'fromClass']);
    if ($custom !== null) {
        $container->delegator('product', $custom === 'array' ? [$owner, 'custom'] : $owner . '::fromClass');
    }
    if (!$loaded) {
        class_alias(LateRouteDecorator::class, $owner);
    }

    $first = $container->get('product');
    $container->set($owner, new InstanceDecorator(':replacement'));
    $current = $container->get('product');

    if ($custom === null) {
        expect($first)->toEqual(new Product('base:static'))
            ->and($current)->toBe($first)
            ->and(LateRouteDecorator::$calls)->toBe(1)
            ->and($executor->resolutions)->toBe(0);
    } else {
        expect($first)->toEqual(new Product('base:static:first'))
            ->and($current)->toEqual(new Product('base:static:replacement'))
            ->and(LateRouteDecorator::$calls)->toBe(2)
            ->and($executor->resolutions)->toBe(2);
    }
})->with([
    'only a late native pair' => null,
    'custom array resolves the same owner' => 'array',
    'custom string resolves the same owner' => 'string',
])->with(['loaded at registration' => true, 'loaded before first lookup' => false]);

test('native static alias strings do not depend on an unused delimiter prefix', function (string $method): void {
    StaticDecorator::$calls = 0;
    $prefix = __NAMESPACE__ . '\\UnusedOwner_' . bin2hex(random_bytes(5));
    $class = $prefix . '::Decorator';
    class_alias(StaticDecorator::class, $class);
    $specification = $class . '::' . $method;
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $container->delegator('product', $specification);

    expect(is_callable($specification))->toBeTrue()
        ->and($container->resolve($specification))->toBe($specification);
    $original = $container->get('product');
    expect($original)->toEqual(new Product('base:' . $method));

    $container->set($prefix, new \stdClass());

    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->delegator($prefix, static fn(object $entry): object => $entry);
    $container->set($class, new \stdClass());

    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->set($specification, static fn(Product $entry): Product => new Product($entry->value . ':replacement'));
    $replacement = $container->get('product');

    expect($replacement)->not->toBe($original)
        ->and($replacement)->toEqual(new Product('base:replacement'))
        ->and($container->get('product'))->toBe($replacement)
        ->and(StaticDecorator::$calls)->toBe(1);
})->with(['declared', 'dynamic', 'hiddenInstance', 'hiddenStatic']);

test('registering a loaded native alias does not autoload its unused delimiter prefix', function (): void {
    StaticDecorator::$calls = 0;
    $prefix = __NAMESPACE__ . '\\UnusedAutoloadOwner_' . bin2hex(random_bytes(5));
    $class = $prefix . '::Decorator';
    class_alias(StaticDecorator::class, $class);
    $specification = $class . '::declared';
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $attempts = 0;
    $loader = static function (string $requested) use ($prefix, &$attempts): void {
        if ($requested === $prefix) {
            ++$attempts;
            throw new LogicException('An unused prefix must not enter native callable registration.');
        }
    };
    spl_autoload_register($loader);

    try {
        $container->delegator('product', $specification);
        $original = $container->get('product');

        expect($original)->toEqual(new Product('base:declared'))
            ->and($container->get('product'))->toBe($original)
            ->and(StaticDecorator::$calls)->toBe(1)
            ->and($attempts)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('instance method strings keep their actual container owner dependency', function (): void {
    $specification = InstanceDecorator::class . '::decorate';
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $container->set(InstanceDecorator::class, new InstanceDecorator(':first'));
    $container->delegator('product', $specification);
    $original = $container->get('product');

    expect($original)->toEqual(new Product('base:first'))
        ->and($container->get('product'))->toBe($original);

    $container->set(InstanceDecorator::class, new InstanceDecorator(':second'));
    $replacement = $container->get('product');

    expect($replacement)->not->toBe($original)
        ->and($replacement)->toEqual(new Product('base:second'))
        ->and($container->get('product'))->toBe($replacement);
});

test('a late native static route drops the unused owner dependency and keeps exact-id precedence', function (bool $separatorAlias): void {
    StaticDecorator::$calls = 0;
    $prefix = __NAMESPACE__ . '\\PendingOwner_' . bin2hex(random_bytes(5));
    $class = $separatorAlias ? $prefix . '::Decorator' : $prefix;
    $specification = $class . '::declared';
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $container->delegator('product', $specification);
    class_alias(StaticDecorator::class, $class);

    $original = $container->get('product');
    expect($original)->toEqual(new Product('base:declared'));
    $container->set($prefix, new \stdClass());

    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->set('product', new Product('refreshed'));
    $rebuilt = $container->get('product');
    $container->set($prefix, new \stdClass());
    expect($rebuilt)->toEqual(new Product('refreshed:declared'))
        ->and($container->get('product'))->toBe($rebuilt)
        ->and(StaticDecorator::$calls)->toBe(2);

    $container->set($specification, static fn(Product $entry): Product => new Product($entry->value . ':explicit'));
    expect($container->get('product'))->toEqual(new Product('refreshed:explicit'))
        ->and(StaticDecorator::$calls)->toBe(2);
})->with(['ordinary class alias' => false, 'class alias with delimiter' => true]);
