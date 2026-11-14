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

test('external registration preserves results warmed by a nested owner registration', function (bool $sameCandidate): void {
    $calls = 0;
    $selected = static function (Product $entry) use (&$calls): Product {
        ++$calls;
        return new Product($entry->value . ':selected');
    };
    $container = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'owner' => static fn(Product $entry): Product => new Product($entry->value . ':local'),
    ])->addDelegator('entry', 'owner')->build();
    expect($container->get('entry'))->toEqual(new Product('base:local'));

    $earlier = new CallbackContainer(['owner' => $selected], static function (): void {});
    $nested = false;
    $warmed = null;
    $candidate = null;
    $candidate = new CallbackContainer([
        'owner' => $sameCandidate
            ? $selected
            : static fn(Product $entry): Product => new Product($entry->value . ':later'),
    ], static function (string $id) use ($container, $earlier, $sameCandidate, &$candidate, &$nested, &$warmed): void {
        if ($id !== 'owner' || $nested) {
            return;
        }
        $nested = true;
        if (!$candidate instanceof CallbackContainer) {
            throw new LogicException('The candidate must exist before ownership inspection.');
        }
        $container->addContainer($sameCandidate ? $candidate : $earlier);
        $warmed = $container->get('entry');
    });

    $container->addContainer($candidate);
    $current = $container->get('entry');

    expect($nested)->toBeTrue()
        ->and($current)->toEqual(new Product('base:selected'))
        ->and($current)->toBe($warmed)
        ->and($container->get('entry'))->toBe($current)
        ->and($container->get('owner'))->toBe($selected)
        ->and($calls)->toBe(1);
})->with(['earlier owner' => false, 'same candidate' => true]);

test('a later ownership callback can establish an earlier owner for an already inspected dependency', function (): void {
    $calls = 0;
    $selected = static function (Product $entry) use (&$calls): Product {
        ++$calls;
        return new Product($entry->value . ':selected');
    };
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $container = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'trigger' => new Product('trigger'),
        'owner' => $local,
        'trigger.owner' => $local,
    ])->addDelegator('entry', 'owner')->addDelegator('trigger', 'trigger.owner')->build();
    expect($container->get('entry'))->toEqual(new Product('base:local'));
    $trigger = $container->get('trigger');
    $earlier = new CallbackContainer(['owner' => $selected], static function (): void {});
    $warmed = null;
    $candidate = new CallbackContainer([
        'owner' => static fn(Product $entry): Product => new Product($entry->value . ':later'),
    ], static function (string $id) use ($container, $earlier, &$warmed): void {
        if ($id === 'trigger.owner' && $warmed === null) {
            $container->addContainer($earlier);
            $warmed = $container->get('entry');
        }
    });

    $container->addContainer($candidate);
    $registrationQueries = $candidate->queries;
    $current = $container->get('entry');

    expect($current)->toEqual(new Product('base:selected'))
        ->and($current)->toBe($warmed)
        ->and($container->get('entry'))->toBe($current)
        ->and($container->get('trigger'))->toBe($trigger)
        ->and($calls)->toBe(1)
        ->and($registrationQueries)->toBe(['owner' => 1, 'trigger.owner' => 1]);
});

test('external registration does not inspect a candidate for an already owned dependency', function (): void {
    $selected = static fn(Product $entry): Product => new Product($entry->value . ':selected');
    $container = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'owner' => static fn(Product $entry): Product => new Product($entry->value . ':local'),
    ])->addDelegator('entry', 'owner')->build();
    $container->addContainer(new CallbackContainer(['owner' => $selected], static function (): void {}));
    $warmed = $container->get('entry');
    $candidate = new CallbackContainer(['owner' => $selected], static function (string $id): void {
        if ($id === 'owner') {
            throw new LogicException('The lower-priority candidate must not inspect this owned dependency.');
        }
    });

    $container->addContainer($candidate);

    expect($candidate->queries)->toBe([])
        ->and($container->get('entry'))->toBe($warmed);
});

test('external ownership inspection preserves results warmed during successive nested registrations', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $selected = static fn(Product $entry): Product => new Product($entry->value . ':selected');
    $container = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'second' => new Product('second'),
        'trigger' => new Product('trigger'),
        'first.owner' => $local,
        'second.owner' => $local,
        'trigger.owner' => $local,
    ])->addDelegator('first', 'first.owner')
        ->addDelegator('second', 'second.owner')
        ->addDelegator('trigger', 'trigger.owner')
        ->build();
    $firstOwner = new CallbackContainer(['first.owner' => $selected], static function (): void {});
    $secondOwner = new CallbackContainer(['second.owner' => $selected], static function (): void {});
    $inspection = new class () {
        public bool $again = false;
    };
    $warmed = [];
    $observer = new CallbackContainer([], static function (string $id) use ($container, $secondOwner, $inspection, &$warmed): void {
        if ($id === 'trigger.owner' && $inspection->again) {
            $inspection->again = false;
            $container->addContainer($secondOwner);
            $warmed['second'] = $container->get('second');
        }
    });
    $container->addContainer($observer);
    $candidate = new CallbackContainer([
        'first.owner' => $local,
        'second.owner' => $local,
        'trigger.owner' => $local,
    ], static function (string $id) use ($container, $firstOwner, $inspection, &$warmed): void {
        if ($id === 'trigger.owner') {
            $container->addContainer($firstOwner);
            $warmed['first'] = $container->get('first');
            $inspection->again = true;
        }
    });

    $container->addContainer($candidate);

    expect($candidate->queries)->toBe(['first.owner' => 1, 'second.owner' => 1, 'trigger.owner' => 1])
        ->and($warmed['first'])->toEqual(new Product('first:selected'));
    foreach ($warmed as $id => $entry) {
        expect($container->get($id))->toBe($entry)
            ->and($container->get($id))->toBe($entry);
    }
    expect($warmed['second'] ?? null)->toEqual(new Product('second:selected'));
});
