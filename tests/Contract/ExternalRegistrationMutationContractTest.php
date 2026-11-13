<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalRegistrationMutation;

use Closure;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final class CallbackContainer implements ContainerInterface
{
    /** @var array<string, int> */
    public array $queries = [];

    /**
     * @param array<string, mixed> $entries
     * @param Closure(string):void $onHas
     */
    public function __construct(private readonly array $entries, private readonly Closure $onHas) {}

    public function has(string $id): bool
    {
        $this->queries[$id] = ($this->queries[$id] ?? 0) + 1;
        ($this->onHas)($id);
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new LogicException('Unexpected external lookup.');
    }
}

test('external registration discovers deferred dependencies added by ownership callbacks', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':external');
    $container = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'second' => new Product('second'),
        'third' => new Product('third'),
        'unrelated' => new Product('unrelated'),
        'first.decorator' => $local,
        'second.decorator' => $local,
        'third.decorator' => $local,
    ])->build();
    $container->delegator('first', 'first.decorator');
    $container->delegator('unrelated', $local);
    $first = $container->get('first');
    $unrelated = $container->get('unrelated');
    $warmed = [];
    $external = new CallbackContainer([
        'second.decorator' => $remote,
        'third.decorator' => $remote,
    ], static function (string $id) use ($container, &$warmed): void {
        $entry = match ($id) {
            'first.decorator' => 'second',
            'second.decorator' => 'third',
            default => null,
        };
        if ($entry !== null && !isset($warmed[$entry])) {
            $container->delegator($entry, $entry . '.decorator');
            $warmed[$entry] = $container->get($entry);
        }
    });

    $container->addContainer($external);

    $registrationQueries = $external->queries;
    foreach (['second', 'third'] as $id) {
        $current = $container->get($id);
        expect($warmed[$id])->toEqual(new Product($id . ':local'))
            ->and($current)->toEqual(new Product($id . ':external'))
            ->and($current)->not->toBe($warmed[$id])
            ->and($container->get($id))->toBe($current);
    }
    expect($container->get('first'))->toBe($first)
        ->and($container->get('unrelated'))->toBe($unrelated)
        ->and($registrationQueries)->toBe(['first.decorator' => 1, 'second.decorator' => 1, 'third.decorator' => 1]);
});

test('external registration invalidates new dependents of an already inspected dependency', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':external');
    $container = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'trigger' => new Product('trigger'),
        'late' => new Product('late'),
        'first.decorator' => $local,
        'trigger.decorator' => $local,
    ])->build();
    $container->delegator('first', 'first.decorator');
    $container->delegator('trigger', 'trigger.decorator');
    $trigger = $container->get('trigger');
    $warmed = null;
    $external = new CallbackContainer(['first.decorator' => $remote], static function (string $id) use ($container, &$warmed): void {
        if ($id === 'trigger.decorator' && $warmed === null) {
            $container->delegator('late', 'first.decorator');
            $warmed = $container->get('late');
        }
    });

    $container->addContainer($external);

    expect($external->queries)->toBe(['first.decorator' => 1, 'trigger.decorator' => 1])
        ->and($warmed)->toEqual(new Product('late:local'))
        ->and($container->get('first'))->toEqual(new Product('first:external'))
        ->and($container->get('late'))->toEqual(new Product('late:external'))
        ->and($container->get('late'))->not->toBe($warmed)
        ->and($container->get('trigger'))->toBe($trigger);
});

test('a newly discovered ownership failure rejects the external container and preserves nested mutations', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':external');
    $container = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'second' => new Product('second'),
        'first.decorator' => $local,
        'second.decorator' => $local,
    ])->build();
    $container->delegator('first', 'first.decorator');
    $first = $container->get('first');
    $warmed = null;
    $external = new CallbackContainer(['first.decorator' => $remote], static function (string $id) use ($container, &$warmed): void {
        if ($id === 'first.decorator' && $warmed === null) {
            $container->delegator('second', 'second.decorator');
            $warmed = $container->get('second');
        }
        if ($id === 'second.decorator') {
            throw new LogicException('New dependency cannot be inspected.');
        }
    });

    expect(fn() => $container->addContainer($external))
        ->toThrow(InvalidConfigurationException::class, 'Failed to inspect an external container while registering it.');
    expect($external->queries)->toBe(['first.decorator' => 1, 'second.decorator' => 1])
        ->and($container->get('first'))->toBe($first)
        ->and($container->get('second'))->toBe($warmed)
        ->and($container->get('second'))->toEqual(new Product('second:local'))
        ->and($container->get('second.decorator'))->toBe($local);
});
