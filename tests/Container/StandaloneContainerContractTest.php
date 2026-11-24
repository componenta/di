<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Container\StandaloneContainerContract;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableResolver;
use Componenta\DI\Container;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerConstruction;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class StandaloneProduct
{
    /** @param array<string|int,mixed> $parameters */
    public function __construct(public array $parameters) {}
}

final class StandaloneEntryResolver implements EntryResolverInterface
{
    public function can(string $id): bool
    {
        return $id === 'product';
    }

    public function resolve(string $id, EntryParameters $params): mixed
    {
        if (!$this->can($id)) {
            throw new NotFoundException('Missing entry: ' . $id);
        }
        return new StandaloneProduct($params->values);
    }
}

test('a container can use an entry resolver without runtime definition support', function (): void {
    $container = new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        aliases: ['alias' => 'product'],
        services: ['provided' => 'value'],
    );
    expect($container->get('provided'))->toBe('value');
    $first = $container->get('alias');
    $fresh = $container->make('product', ['value' => 'fresh']);
    if (!$fresh instanceof StandaloneProduct) {
        throw new \LogicException('Expected the standalone product.');
    }

    expect($first)->toBeInstanceOf(StandaloneProduct::class)
        ->and($container->get('product'))->toBe($first)
        ->and($fresh->parameters)->toBe(['value' => 'fresh'])
        ->and(fn() => $container->set('product', new ReferenceDefinition('different')))
        ->toThrow(InvalidConfigurationException::class, 'Definition of type "' . ReferenceDefinition::class . '" is not supported.')
        ->and($container->get('product'))->toBe($first);
});

test('standalone container bootstrap accepts typed core services and rejects inconsistent replacements', function (): void {
    $environment = new Environment(['MODE' => 'standalone']);
    $config = new Config([], $environment);
    $executor = (new ContainerBuilder())->build();
    $container = ContainerConstruction::create(
        new StandaloneEntryResolver(),
        new AliasResolver(),
        $executor,
        bootstrapServices: [Config::class => $config, Environment::class => $environment],
    );

    expect($container->get(Config::class))->toBe($config)
        ->and($container->get(Environment::class))->toBe($environment)
        ->and(fn() => ContainerConstruction::create(
            new StandaloneEntryResolver(),
            new AliasResolver(),
            $executor,
            bootstrapServices: [Config::class => $environment],
        ))->toThrow(
            InvalidConfigurationException::class,
            'Bootstrap service "' . Config::class . '" must implement ' . Config::class . '; got ' . Environment::class . '.',
        );
});

test('standalone container bootstrap refuses ids outside the supported core services', function (string|int $id): void {
    expect(fn() => ContainerConstruction::create(
        new StandaloneEntryResolver(),
        new AliasResolver(),
        (new ContainerBuilder())->build(),
        bootstrapServices: [$id => new \stdClass()],
    ))->toThrow(InvalidConfigurationException::class, 'Unsupported bootstrap service id "' . $id . '".');
})->with(['application', Container::class, 7]);

test('standalone construction rejects protected alias sources before attaching its resolver', function (string $id): void {
    $resolver = new CompositeResolver(new StandaloneEntryResolver());
    $executor = (new ContainerBuilder())->build();
    $seed = new StandaloneProduct(['source' => 'seed']);
    expect(fn() => new Container(
        $resolver,
        $executor,
        aliases: [$id => 'product'],
        services: ['seed' => $seed],
    ))->toThrow(InvalidConfigurationException::class, 'Cannot replace protected DI alias "' . $id . '".');

    $container = new Container($resolver, $executor, services: ['seed' => $seed]);

    expect($container->get(ContainerInterface::class))->toBe($container)
        ->and($container->get('seed'))->toBe($seed);
})->with([
    Container::class,
    ContainerInterface::class,
    CallableExecutorInterface::class,
    Config::class,
    Environment::class,
    DependencyDefinitions::class,
    'config',
]);

test('standalone construction preserves the canonical config alias and application aliases to core services', function (): void {
    $environment = new Environment(['MODE' => 'standalone']);
    $config = new Config([], $environment);
    $container = ContainerConstruction::create(
        new StandaloneEntryResolver(),
        new AliasResolver([
            'config' => Config::class,
            'application.config' => 'config',
            'application.container' => ContainerInterface::class,
        ]),
        (new ContainerBuilder())->build(),
        bootstrapServices: [Config::class => $config, Environment::class => $environment],
    );

    expect($container->get('config'))->toBe($config)
        ->and($container->get('application.config'))->toBe($config)
        ->and($container->get('application.container'))->toBe($container)
        ->and($container->get(Config::class))->toBe($config)
        ->and($container->get(Environment::class))->toBe($environment);
});

test('standalone construction rejects an indirect reserved config alias through a mutable application alias', function (): void {
    expect(fn() => new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        aliases: ['config' => 'application.config', 'application.config' => Config::class],
    ))->toThrow(InvalidConfigurationException::class, 'Cannot replace protected DI alias "config".');
});

final class StandaloneProxyProduct
{
    public string $value = 'default';
}

final class StandaloneProxyFactory implements \Componenta\DI\ProxyFactoryInterface
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T):void $initializer
     * @return T
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        $this->calls[] = 'lazy';
        return (new \Componenta\DI\ProxyFactory())->makeLazy($class, $initializer);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T):T $factory
     * @return T
     */
    public function makeProxy(string $class, callable $factory): object
    {
        $this->calls[] = 'proxy';
        return (new \Componenta\DI\ProxyFactory())->makeProxy($class, $factory);
    }
}

test('standalone lazy and proxy creation uses the supplied proxy factory', function (): void {
    $factory = new StandaloneProxyFactory();
    $container = new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        proxyFactory: $factory,
    );
    $lazy = $container->makeLazy(StandaloneProxyProduct::class, static function (StandaloneProxyProduct $entry): void {
        $entry->value = 'initialized';
    });
    $proxy = $container->makeProxy(StandaloneProxyProduct::class, static function (): StandaloneProxyProduct {
        $entry = new StandaloneProxyProduct();
        $entry->value = 'factory';
        return $entry;
    });

    expect($factory->calls)->toBe(['lazy', 'proxy'])
        ->and($lazy->value)->toBe('initialized')
        ->and($proxy->value)->toBe('factory');
});

test('standalone shared lookups preserve supplied values through alias retarget and replacement', function (): void {
    $container = new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        aliases: ['requested' => 'first', 'sibling' => 'first'],
        services: ['first' => null, 'second' => false],
    );

    expect($container->get('requested'))->toBeNull()
        ->and($container->get('requested'))->toBeNull()
        ->and($container->get('sibling'))->toBeNull()
        ->and($container->get('first'))->toBeNull();

    $container->alias('requested', 'second');

    expect($container->get('requested'))->toBeFalse()
        ->and($container->get('requested'))->toBeFalse()
        ->and($container->get('sibling'))->toBeNull()
        ->and($container->get('second'))->toBeFalse();

    $replacement = new StandaloneProduct(['source' => 'replacement']);
    $container->set('second', $replacement);

    expect($container->get('requested'))->toBe($replacement)
        ->and($container->get('requested'))->toBe($replacement)
        ->and($container->get('second'))->toBe($replacement)
        ->and($container->get('sibling'))->toBeNull();
});

test('standalone cache seeds keep caller reference changes out of cold and warmed aliases', function (bool $warm): void {
    $original = new StandaloneProduct(['source' => 'original']);
    $seed = $original;
    $container = new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        aliases: ['first.alias' => 'seed', 'second.alias' => 'seed'],
        services: ['seed' => &$seed],
    );
    if ($warm) {
        expect($container->get('first.alias'))->toBe($original);
    }
    $seed = new StandaloneProduct(['source' => 'caller replacement']);

    expect($container->get('first.alias'))->toBe($original)
        ->and($container->get('second.alias'))->toBe($original)
        ->and($container->get('seed'))->toBe($original)
        ->and($seed)->not->toBe($original);
})->with(['cold aliases' => false, 'warmed alias' => true]);

test('standalone entry replacement does not write through a caller seed reference', function (): void {
    $original = new StandaloneProduct(['source' => 'original']);
    $seed = $original;
    $container = new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        aliases: ['alias' => 'seed'],
        services: ['seed' => &$seed],
    );
    expect($container->get('alias'))->toBe($original);
    $replacement = new StandaloneProduct(['source' => 'container replacement']);

    $container->set('seed', $replacement);

    expect($seed)->toBe($original)
        ->and($container->get('seed'))->toBe($replacement)
        ->and($container->get('alias'))->toBe($replacement);
});

test('standalone core bootstrap isolates protected entries from supplied seed reference cells', function (): void {
    $original = new \stdClass();
    $seed = $original;
    $container = ContainerConstruction::create(
        new StandaloneEntryResolver(),
        new AliasResolver(['core.alias' => ContainerInterface::class]),
        (new ContainerBuilder())->build(),
        cache: new EntryCache([ContainerInterface::class => &$seed]),
    );
    expect($seed)->toBe($original);
    $seed = new \stdClass();

    expect($container->get(ContainerInterface::class))->toBe($container)
        ->and($container->get('core.alias'))->toBe($container)
        ->and($seed)->not->toBe($container);
});

test('standalone seed ownership preserves nested references and service object identity', function (): void {
    $nested = 'initial';
    $object = new \stdClass();
    $service = ['nested' => &$nested, 'object' => $object];
    $container = new Container(
        new StandaloneEntryResolver(),
        (new ContainerBuilder())->build(),
        services: ['seed' => &$service],
    );
    $nested = 'updated';
    $service['caller.only'] = true;
    $value = $container->get('seed');

    expect($value)->toBe(['nested' => 'updated', 'object' => $object]);
});

test('standalone mutations refresh supplied delegator pipelines for affected requested ids', function (): void {
    $decorators = (new ContainerBuilder())->addService(
        'selected.decorator',
        static fn(StandaloneProduct $entry): StandaloneProduct => new StandaloneProduct($entry->parameters + ['decorator' => 'initial']),
    )->build();
    $container = ContainerConstruction::create(
        new StandaloneEntryResolver(),
        new AliasResolver(['requested' => 'first']),
        (new ContainerBuilder())->build(),
        cache: new EntryCache([
            'first' => new StandaloneProduct(['source' => 'first']),
            'second' => new StandaloneProduct(['source' => 'second']),
        ]),
        delegators: new DelegatorRegistry($decorators),
    );
    $container->delegator('first', 'selected.decorator');
    $container->delegator('requested', 'selected.decorator');

    expect($container->get('first'))->toEqual(new StandaloneProduct(['source' => 'first', 'decorator' => 'initial']))
        ->and($container->get('requested'))->toEqual(new StandaloneProduct(['source' => 'first', 'decorator' => 'initial']));

    $decorators->set('selected.decorator', static fn(StandaloneProduct $entry): StandaloneProduct => new StandaloneProduct($entry->parameters + ['decorator' => 'replacement']));
    $container->set('requested', new StandaloneProduct(['source' => 'replacement']));
    $first = $container->get('first');

    expect($first)->toEqual(new StandaloneProduct(['source' => 'replacement', 'decorator' => 'replacement']))
        ->and($container->get('requested'))->toEqual(new StandaloneProduct(['source' => 'replacement', 'decorator' => 'replacement']));

    $decorators->set('selected.decorator', static fn(StandaloneProduct $entry): StandaloneProduct => new StandaloneProduct($entry->parameters + ['decorator' => 'retargeted']));
    $container->alias('requested', 'second');
    $requested = $container->get('requested');

    expect($requested)->toEqual(new StandaloneProduct(['source' => 'second', 'decorator' => 'retargeted']))
        ->and($container->get('requested'))->toBe($requested)
        ->and($container->get('first'))->toBe($first)
        ->and($container->get('second'))->toEqual(new StandaloneProduct(['source' => 'second']));
});

final class StandaloneDecoratorExecutor implements CallableExecutorInterface
{
    public Container $container;

    /** @param Closure(Container):callable $select */
    public function __construct(private readonly Closure $select) {}

    public function call(mixed $callable, array $params = []): mixed
    {
        throw new LogicException('Delegators invoke the selected callable directly.');
    }

    public function resolve(mixed $callable): callable
    {
        if ($callable !== 'selected.decorator') {
            throw new LogicException('Expected the application decorator token.');
        }
        return ($this->select)($this->container);
    }
}

/** @return Closure(StandaloneProduct):StandaloneProduct */
function standaloneDecorator(string $label): Closure
{
    return static fn(StandaloneProduct $entry): StandaloneProduct => new StandaloneProduct($entry->parameters + ['decorator' => $label]);
}

/**
 * @param Closure(Container):callable $select
 * @param array<string,mixed> $services
 * @param array<string,string> $aliases
 */
function standaloneDelegatedContainer(Closure $select, array $services, array $aliases = []): Container
{
    $executor = new StandaloneDecoratorExecutor($select);
    $container = new Container(new StandaloneEntryResolver(), $executor, aliases: $aliases, services: $services);
    $executor->container = $container;
    $container->delegator('product', 'selected.decorator');
    return $container;
}

function boundDecorator(Container $container, string $id): Closure
{
    $decorator = $container->get($id);
    if (!$decorator instanceof Closure) {
        throw new LogicException('Expected a bound decorator.');
    }
    return $decorator;
}

test('custom standalone delegators track the selected service and its current alias target', function (bool $nativeLookup): void {
    $container = standaloneDelegatedContainer(
        $nativeLookup
            ? static fn(Container $container): callable => new CallableResolver($container)->resolve('active.decorator')
            : static fn(Container $container): Closure => boundDecorator($container, 'active.decorator'),
        ['first.decorator' => standaloneDecorator('initial'), 'second.decorator' => standaloneDecorator('second')],
        ['active.decorator' => 'first.decorator'],
    );
    $first = $container->get('product');
    expect($first)->toEqual(new StandaloneProduct(['decorator' => 'initial']));

    $container->set('unrelated', standaloneDecorator('unrelated'));
    expect($container->get('product'))->toBe($first);

    $container->set('first.decorator', standaloneDecorator('replacement'));
    expect($container->get('product'))->toEqual(new StandaloneProduct(['decorator' => 'replacement']));

    $container->alias('active.decorator', 'second.decorator');
    $second = $container->get('product');
    expect($second)->toEqual(new StandaloneProduct(['decorator' => 'second']));

    $container->set('first.decorator', standaloneDecorator('retired'));
    expect($container->get('product'))->toBe($second);
})->with(['public get' => false, 'resolvable lookup' => true]);

test('custom standalone delegators refresh a route selected by a previously missing binding', function (): void {
    $container = standaloneDelegatedContainer(
        static fn(Container $container): Closure => boundDecorator(
            $container,
            $container->has('override.decorator') ? 'override.decorator' : 'default.decorator',
        ),
        ['default.decorator' => standaloneDecorator('default')],
    );
    expect($container->get('product'))->toEqual(new StandaloneProduct(['decorator' => 'default']));

    $container->set('override.decorator', standaloneDecorator('override'));

    expect($container->get('product'))->toEqual(new StandaloneProduct(['decorator' => 'override']));
});

test('custom standalone delegators publish a selection only while its observed bindings stay current', function (
    bool $suspended,
    bool $affected,
): void {
    $selection = 0;
    $changedId = $affected ? 'actual.decorator' : 'unrelated';
    $container = standaloneDelegatedContainer(
        static function (Container $container) use (&$selection, $suspended, $changedId): Closure {
            $decorator = boundDecorator($container, 'actual.decorator');
            if (++$selection === 1) {
                if ($suspended) {
                    Fiber::suspend('selected');
                } else {
                    $container->set($changedId, standaloneDecorator('replacement'));
                }
            }
            return $decorator;
        },
        ['actual.decorator' => standaloneDecorator('initial')],
    );
    if ($suspended) {
        $fiber = new Fiber(static fn(): mixed => $container->get('product'));
        expect($fiber->start())->toBe('selected');
        expect($container->has($changedId))->toBe($affected);
        $container->set($changedId, standaloneDecorator('replacement'));
        $fiber->resume();
        $first = $fiber->getReturn();
    } else {
        $first = $container->get('product');
    }
    expect($first)->toEqual(new StandaloneProduct(['decorator' => 'initial']));

    $current = $container->get('product');
    if ($affected) {
        expect($current)->toEqual(new StandaloneProduct(['decorator' => 'replacement']));
    } else {
        expect($current)->toBe($first);
    }
    expect($container->get('product'))->toBe($current)
        ->and($selection)->toBe($affected ? 2 : 1);
})->with(['reentrant' => false, 'suspended' => true])->with(['related' => true, 'unrelated' => false]);

test('standalone construction rejects a cycle guard until its existing resolution is complete', function (): void {
    $guard = new CycleGuard();
    $executor = (new ContainerBuilder())->build();
    $build = static fn(): Container => ContainerConstruction::create(
        new StandaloneEntryResolver(),
        new AliasResolver(['requested' => 'product']),
        $executor,
        cycleGuard: $guard,
    );
    $guard->enter('product');

    try {
        expect($build)->toThrow(InvalidConfigurationException::class, 'An active cycle guard');
    } finally {
        $guard->leave('product');
    }

    expect($build()->get('requested'))->toEqual(new StandaloneProduct([]));
});
