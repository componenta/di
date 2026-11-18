<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ContainerBootstrapRetirement;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\VirtualProxyFactoryInterface;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;

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

    public function resolve(string $id, array $params = []): mixed
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

    $container = new Container(new EntryResolver(), new AliasResolver(), (new ContainerBuilder())->build(), cache: $cache);

    expect($shared)->toBeInstanceOf(Product::class)
        ->and($container->get('product'))->toBe($shared)
        ->and($fresh)->toBeInstanceOf(Product::class)
        ->and($fresh)->not->toBe($shared)
        ->and($lazyValue)->toBe('initialized');
});

test('seed retirement observes the complete core publication and preserves nested mutations', function (string $seedId): void {
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
    $seed = new Seed(static function () use (&$cache, &$observed, $coreIds): void {
        $container = publishedContainer($cache);
        foreach ($coreIds as $id) {
            $observed[$id] = $container->get($id);
        }
        $observed[Config::class] = $container->get(Config::class);
        $observed[Environment::class] = $container->get(Environment::class);
        $container->set('application', 'replacement');
        $container->alias('application.alias', 'application');
    });
    $cache = new EntryCache([$seedId => $seed, 'application' => 'original']);
    unset($seed);

    $container = new Container(
        new EntryResolver(),
        new AliasResolver(),
        (new ContainerBuilder())->build(),
        cache: $cache,
        bootstrapServices: [Config::class => $config, Environment::class => $environment],
    );

    $expected = array_fill_keys($coreIds, $container) + [Config::class => $config, Environment::class => $environment];
    expect($observed)->toBe($expected)
        ->and($container->get('application'))->toBe('replacement')
        ->and($container->get('application.alias'))->toBe('replacement');
})->with(['core value' => ContainerInterface::class, 'bootstrap value' => Environment::class]);

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
        new Container(new EntryResolver(), new AliasResolver(), (new ContainerBuilder())->build(), cache: $cache);
    } catch (Throwable $error) {
        $caught = $error;
    }

    $container = publishedContainer($cache);
    expect($caught)->toBe($failure)
        ->and($observed)->toBe('original')
        ->and($container->get(Container::class))->toBe($container)
        ->and($container->get('application'))->toBe('replacement');
});
