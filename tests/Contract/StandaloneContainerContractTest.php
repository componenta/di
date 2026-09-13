<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;

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
