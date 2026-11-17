<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
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

    public function resolve(string $id, array $params = []): mixed
    {
        if (!$this->can($id)) {
            throw new NotFoundException('Missing entry: ' . $id);
        }
        return new StandaloneProduct($params);
    }
}

test('a container can use an entry resolver without runtime definition support', function (): void {
    $container = new Container(
        new StandaloneEntryResolver(),
        new AliasResolver(['alias' => 'product']),
        (new ContainerBuilder())->build(),
    );
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
    $container = new Container(
        new StandaloneEntryResolver(),
        new AliasResolver(),
        $executor,
        bootstrapServices: [Config::class => $config, Environment::class => $environment],
    );

    expect($container->get(Config::class))->toBe($config)
        ->and($container->get(Environment::class))->toBe($environment)
        ->and(fn() => new Container(
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
    expect(fn() => new Container(
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
    $cache = new EntryCache(['seed' => $seed]);

    expect(fn() => new Container(
        $resolver,
        new AliasResolver([$id => 'product']),
        $executor,
        $cache,
    ))->toThrow(InvalidConfigurationException::class, 'Cannot replace protected DI alias "' . $id . '".');

    $container = new Container($resolver, new AliasResolver(), $executor, $cache);

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
    $container = new Container(
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
        new AliasResolver(['config' => 'application.config', 'application.config' => Config::class]),
        (new ContainerBuilder())->build(),
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
        new AliasResolver(),
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
    $cache = new EntryCache(['first' => null, 'second' => false]);
    $aliases = new AliasResolver(['requested' => 'first', 'sibling' => 'first']);
    $container = new Container(new StandaloneEntryResolver(), $aliases, (new ContainerBuilder())->build(), $cache);

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
