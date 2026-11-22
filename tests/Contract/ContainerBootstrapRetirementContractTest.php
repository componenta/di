<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ContainerBootstrapRetirement;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerConstruction;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\ExternalContainerRegistry;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\VirtualProxyFactoryInterface;
use Error;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;
use WeakReference;

use function Componenta\DI\Tests\Support\assembledContainer;

final class Product
{
    public string $value = 'ordinary';
}

final class EntryResolver implements EntryResolverInterface
{
    public function can(string $id): bool
    {
        return $id === 'product';
    }

    public function resolve(string $id, EntryParameters $params): mixed
    {
        if (!$this->can($id)) {
            throw new NotFoundException($id);
        }
        return new Product();
    }
}

final readonly class Seed
{
    /** @param Closure():void $onRelease */
    public function __construct(private Closure $onRelease) {}

    public function __destruct()
    {
        ($this->onRelease)();
    }
}

function publishedContainer(?EntryCache $cache): Container
{
    if ($cache === null || !$cache->tryGetBase(ContainerInterface::class, $container) || !$container instanceof Container) {
        throw new LogicException('Expected the published container core value.');
    }
    return $container;
}

test('an assembled runtime cannot be used to publish another container', function (): void {
    $firstConfig = new Config([], new Environment(['MODE' => 'first']));
    $secondConfig = new Config([], new Environment(['MODE' => 'second']));
    $resolveConfig = static fn(Config $config): Config => $config;
    [$first, $runtime] = assembledContainer(config: $firstConfig);

    expect($first->call($resolveConfig))->toBe($firstConfig)
        ->and(fn(): array => assembledContainer([
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => true,
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
        ], $secondConfig, runtime: $runtime))->toThrow(InvalidConfigurationException::class, 'already belongs to an assembly');

    [$second] = assembledContainer(config: $secondConfig);
    expect($second->call($resolveConfig))->toBe($secondConfig)
        ->and($first->call($resolveConfig))->toBe($firstConfig);
});

test('a resolution runtime cannot be shallow-cloned before assembly', function (): void {
    $runtime = new ResolutionRuntime();
    $copy = static fn(object $source): object => clone $source;

    expect(fn(): object => $copy($runtime))->toThrow(Error::class, '__clone');
});

test('a cycle guard cannot share the resolution stack with a shallow copy', function (): void {
    $copy = static fn(object $source): object => clone $source;

    expect(fn(): object => $copy(new CycleGuard()))->toThrow(Error::class, '__clone');
});

test('cold collaborator autoloading never exposes an unusable published container', function (): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/ContainerBootstrapCold.php',
        $root . '/vendor/autoload.php',
        $root . '/src',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the cold bootstrap fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr);
    expect(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'autoload_observed' => true,
        'early_errors' => [],
        'value' => 'ready',
        'lazy_value' => 'initialized',
    ]);
});

test('collaborator loading leaves one usable attachment after failure or reentry', function (string $collaborator): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/ContainerConstructionRetry.php',
        $root . '/vendor/autoload.php',
        $root . '/src',
        $collaborator,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the construction retry fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr)
        ->and($stderr)->toBe('')
        ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'autoload_observed' => true,
            'original_failure_preserved' => !str_ends_with($collaborator, '-reentry') && !str_ends_with($collaborator, '-alias'),
            'duplicate_attachment_rejected' => $collaborator === 'writer-reentry',
            'shared_collaborator_rejected' => $collaborator === 'cache-reentry',
            'protected_alias_rejected' => $collaborator === 'writer-core-alias',
            'unexpected_core_publication' => false,
            'nested_owner_preserved' => true,
            'retry_error' => null,
            'result' => ['value' => 'ready', 'self' => true, 'call' => 'invoked'],
        ]);
})->with([
    'entry cache' => 'cache',
    'delegators' => 'delegators',
    'cycle guard' => 'cycles',
    'proxy factory' => 'proxy',
    'ownership helper' => 'ownership',
    'definition writer' => 'writer',
    'reentrant writer construction' => 'writer-reentry',
    'reentrant cache ownership' => 'cache-reentry',
    'protected alias configured during writer loading' => 'writer-core-alias',
    'application alias configured during writer loading' => 'writer-application-alias',
]);

test('automatic garbage collection preserves collaborator ownership and alias routing', function (string $shared): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/ContainerConstructionGc.php',
        $root . '/vendor/autoload.php',
        $root . '/src',
        $shared,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the GC reentry fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr)
        ->and($stderr)->toBe('');
    $observation = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($observation) || !is_int($observation['reentries'] ?? null) || !is_array($observation['violations'] ?? null)) {
        throw new LogicException('Expected the GC fixture result.');
    }
    expect($observation['reentries'])->toBeGreaterThan(0)
        ->and($observation['violations'])->toBe([]);
})->with(['cache', 'root', 'aliases', 'routes']);

test('an attached runtime collaborator cannot be reused and rejection leaves other collaborators available', function (
    string $collaborator,
    string $type,
): void {
    $executor = (new ContainerBuilder())->build();
    $firstAliases = new AliasResolver(['requested' => 'first']);
    $firstValue = new Product();
    $firstCache = new EntryCache(['first' => $firstValue]);
    $firstDelegators = new DelegatorRegistry($executor);
    $firstExternal = new ExternalContainerRegistry();
    $firstGuard = new CycleGuard();
    $first = ContainerConstruction::create(
        new EntryResolver(),
        $firstAliases,
        $executor,
        $firstCache,
        $firstDelegators,
        $firstExternal,
        $firstGuard,
    );
    expect($first->get('requested'))->toBe($firstValue);

    $root = new CompositeResolver(new EntryResolver());
    $aliases = new AliasResolver(['requested' => 'second']);
    $value = new Product();
    $cache = new EntryCache(['second' => $value]);
    $delegators = new DelegatorRegistry($executor);
    $external = new ExternalContainerRegistry();
    $guard = new CycleGuard();
    $build = static fn(bool $reuse): Container => ContainerConstruction::create(
        $root,
        $reuse && $collaborator === 'aliases' ? $firstAliases : $aliases,
        $executor,
        $reuse && $collaborator === 'cache' ? $firstCache : $cache,
        $reuse && $collaborator === 'delegators' ? $firstDelegators : $delegators,
        $reuse && $collaborator === 'external' ? $firstExternal : $external,
        $reuse && $collaborator === 'guard' ? $firstGuard : $guard,
    );

    expect(static fn(): Container => $build(true))->toThrow(InvalidConfigurationException::class, $type);
    $second = $build(false);

    expect($first->get(ContainerInterface::class))->toBe($first)
        ->and($first->get('requested'))->toBe($firstValue)
        ->and($second->get(ContainerInterface::class))->toBe($second)
        ->and($second->get('requested'))->toBe($value);
})->with([
    'aliases' => ['aliases', AliasResolver::class],
    'entry cache' => ['cache', EntryCache::class],
    'delegators' => ['delegators', DelegatorRegistry::class],
    'external registry' => ['external', ExternalContainerRegistry::class],
    'cycle guard' => ['guard', CycleGuard::class],
]);

test('retained runtime collaborators cannot mutate the published container', function (): void {
    $executor = (new ContainerBuilder())->build();
    $cache = new EntryCache();
    $delegators = new DelegatorRegistry($executor);
    $external = new ExternalContainerRegistry();
    $guard = new CycleGuard();
    $container = ContainerConstruction::create(new EntryResolver(), new AliasResolver(), $executor, $cache, $delegators, $external, $guard);
    $id = ContainerInterface::class;
    $replacement = new Product();
    $prepared = $delegators->prepare($id, static fn(): Product => $replacement);
    $provider = (new ContainerBuilder())->addService('external', $replacement)->build();

    expect(fn() => $cache->putBase($id, $replacement))->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $cache->putResolved($id, $id, $replacement))->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $cache->__construct([$id => $replacement]))->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $delegators->register($id, $prepared, new MutationRetirement($id)))->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $external->register($provider))->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $guard->enter('product'))->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $guard->leave('product'))->toThrow(InvalidConfigurationException::class)
        ->and($container->get($id))->toBe($container)
        ->and($container->has('external'))->toBeFalse();

    $container->set('value', 'before');
    $container->delegator('value', static fn(string $value): string => $value . ':after');
    $container->addContainer($provider);

    expect($container->make('product'))->toBeInstanceOf(Product::class)
        ->and($container->get('value'))->toBe('before:after')
        ->and($container->get('external'))->toBe($replacement);
});

test('direct construction rejects a cache containing previously resolved entries', function (): void {
    $cache = new EntryCache();
    $id = ContainerInterface::class;
    $cache->putResolved($id, $id, new Product());
    $executor = (new ContainerBuilder())->build();

    expect(fn() => ContainerConstruction::create(new EntryResolver(), new AliasResolver(), $executor, $cache))
        ->toThrow(InvalidConfigurationException::class);
});

test('direct construction requires delegators to be registered through the container', function (): void {
    $executor = (new ContainerBuilder())->build();
    $delegators = new DelegatorRegistry($executor);
    $id = ContainerInterface::class;
    $delegators->register($id, $delegators->prepare($id, static fn(): Product => new Product()), new MutationRetirement($id));

    expect(fn() => ContainerConstruction::create(new EntryResolver(), new AliasResolver(), $executor, delegators: $delegators))
        ->toThrow(InvalidConfigurationException::class);
});

test('editing an attached alias requires a container mutation and keeps an independent snapshot writable', function (): void {
    $aliases = new AliasResolver();
    $aliases->set('requested', 'first');
    $first = new Product();
    $second = new Product();
    $unrelated = new Product();
    $container = ContainerConstruction::create(
        new EntryResolver(),
        $aliases,
        (new ContainerBuilder())->build(),
        cache: new EntryCache(['first' => $first, 'second' => $second, 'unrelated' => $unrelated]),
    );
    expect($container->get('requested'))->toBe($first)
        ->and($container->get('unrelated'))->toBe($unrelated);

    expect(static fn(): AliasResolver => $aliases->set('requested', 'second'))
        ->toThrow(InvalidConfigurationException::class);
    expect(static function () use ($aliases): void {
        $aliases->__construct(['requested' => 'second']);
    })->toThrow(InvalidConfigurationException::class);
    expect($aliases->target('requested'))->toBe('first')
        ->and($container->get('requested'))->toBe($first);

    $snapshot = clone $aliases;
    $snapshot->set('requested', 'second');
    expect($snapshot->resolve('requested'))->toBe('second')
        ->and($aliases->resolve('requested'))->toBe('first');
    $container->alias('requested', 'second');
    expect($aliases->resolve('requested'))->toBe('second')
        ->and($container->get('requested'))->toBe($second)
        ->and($container->get('unrelated'))->toBe($unrelated);
});

test('collaborator ownership does not keep a container or cache alive', function (): void {
    $aliases = new AliasResolver();
    $cache = new EntryCache();
    $container = ContainerConstruction::create(new EntryResolver(), $aliases, (new ContainerBuilder())->build(), cache: $cache);
    $containerRef = WeakReference::create($container);
    $cacheRef = WeakReference::create($cache);
    unset($container, $cache);
    gc_collect_cycles();

    expect($containerRef->get())->toBeNull()
        ->and($cacheRef->get())->toBeNull();
    expect(static fn(): AliasResolver => $aliases->set('requested', 'product'))->toThrow(InvalidConfigurationException::class);
});

test('retiring a core seed can resolve create and initialize lazy objects through the published container', function (): void {
    $cache = null;
    $shared = null;
    $fresh = null;
    $lazyValue = null;
    $seed = new Seed(static function () use (&$cache, &$shared, &$fresh, &$lazyValue): void {
        $container = publishedContainer($cache);
        $shared = $container->get('product');
        $fresh = $container->make('product');
        $lazy = $container->makeLazy(Product::class, static function (Product $entry): void {
            $entry->value = 'initialized';
        });
        $lazyValue = $lazy->value;
    });
    $cache = new EntryCache([ContainerInterface::class => $seed]);
    unset($seed);

    $container = ContainerConstruction::create(new EntryResolver(), new AliasResolver(), (new ContainerBuilder())->build(), cache: $cache);

    expect($shared)->toBeInstanceOf(Product::class)
        ->and($container->get('product'))->toBe($shared)
        ->and($fresh)->toBeInstanceOf(Product::class)
        ->and($fresh)->not->toBe($shared)
        ->and($lazyValue)->toBe('initialized');
});

test('seed retirement observes the complete core publication and preserves nested mutations', function (string $firstSeedId, string $secondSeedId): void {
    $coreIds = [
        ContainerInterface::class,
        FactoryInterface::class,
        CallableInvokerInterface::class,
        CallableResolverInterface::class,
        CallableExecutorInterface::class,
        ProxyFactoryInterface::class,
        LazyObjectFactoryInterface::class,
        VirtualProxyFactoryInterface::class,
        Container::class,
    ];
    $environment = new Environment(['MODE' => 'bootstrap']);
    $config = new Config([], $environment);
    $cache = null;
    $observed = [];
    $observe = static function () use (&$cache, &$observed, $coreIds): void {
        $container = publishedContainer($cache);
        $snapshot = [];
        foreach ($coreIds as $id) {
            $snapshot[$id] = $container->get($id);
        }
        $snapshot[Config::class] = $container->get(Config::class);
        $snapshot[Environment::class] = $container->get(Environment::class);
        $observed[] = $snapshot;
        $container->set('application', 'replacement');
        $container->alias('application.alias', 'application');
    };
    $cache = new EntryCache([
        $firstSeedId => new Seed($observe),
        $secondSeedId => new Seed($observe),
        'application' => 'original',
    ]);

    $container = ContainerConstruction::create(
        new EntryResolver(),
        new AliasResolver(),
        (new ContainerBuilder())->build(),
        cache: $cache,
        bootstrapServices: [Config::class => $config, Environment::class => $environment],
    );

    $expected = array_fill_keys($coreIds, $container) + [Config::class => $config, Environment::class => $environment];
    expect($observed)->toBe([$expected, $expected])
        ->and($container->get('application'))->toBe('replacement')
        ->and($container->get('application.alias'))->toBe('replacement');
})->with([
    'adjacent core values' => [ContainerInterface::class, FactoryInterface::class],
    'adjacent bootstrap values' => [Config::class, Environment::class],
]);

test('a throwing bootstrap seed preserves its native exception after publishing a usable container', function (): void {
    $cache = null;
    $observed = null;
    $failure = new LogicException('Seed retirement failed.');
    $seed = new Seed(static function () use (&$cache, &$observed, $failure): void {
        $container = publishedContainer($cache);
        $observed = $container->get('application');
        $container->set('application', 'replacement');
        throw $failure;
    });
    $cache = new EntryCache([ContainerInterface::class => $seed, 'application' => 'original']);
    unset($seed);
    $caught = null;

    try {
        ContainerConstruction::create(new EntryResolver(), new AliasResolver(), (new ContainerBuilder())->build(), cache: $cache);
    } catch (Throwable $error) {
        $caught = $error;
    }

    $container = publishedContainer($cache);
    expect($caught)->toBe($failure)
        ->and($observed)->toBe('original')
        ->and($container->get(Container::class))->toBe($container)
        ->and($container->get('application'))->toBe('replacement');
});
