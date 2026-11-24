<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\ExternalRegistrationCompletionContract;

use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $value) {}
}

#[Lazy]
final readonly class LazyProduct
{
    public function __construct(#[EntryId('source')] public string $value) {}
}

#[Proxy]
final readonly class ProxyProduct
{
    public function __construct(#[EntryId('source')] public string $value) {}
}

final readonly class InjectedProxy
{
    public function __construct(#[Make('proxy.backing'), Proxy] public Product $product) {}
}

final readonly class OwnershipContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     * @param Closure(string):bool|null $onHas
     */
    public function __construct(private array $entries, private ?Closure $onHas = null) {}

    public function has(string $id): bool
    {
        return $this->onHas === null ? array_key_exists($id, $this->entries) : ($this->onHas)($id);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new LogicException('Unexpected external lookup: ' . $id);
    }
}

test('registration ownership probes allow nested lookups without reentering an existing provider', function (bool $read, bool $takeover): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':remote');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $state = new class () {
        public bool $armed = false;
        public bool $insideProbe = false;
        /** @var list<mixed> */
        public array $nested = [];
    };
    $existing = new OwnershipContainer([], static function (string $id) use ($container, $read, $state): bool {
        if ($id !== 'owner' || !$state->armed) {
            return false;
        }
        if ($state->insideProbe) {
            throw new LogicException('The same ownership callback was entered recursively.');
        }
        $state->insideProbe = true;
        try {
            $state->nested[] = $read ? $container->get($id) : $container->has($id);
        } finally {
            $state->insideProbe = false;
        }
        return false;
    });
    $container->addContainer($existing);
    $warm = $container->get('entry');
    $candidate = new OwnershipContainer($takeover ? ['owner' => $remote] : ['candidate.entry' => 'registered']);

    $state->armed = true;
    $container->addContainer($candidate);
    $state->armed = false;

    expect($state->nested)->not->toBeEmpty();
    foreach ($state->nested as $result) {
        expect($result)->toBe($read ? $local : true);
    }
    $entry = $container->get('entry');
    if ($takeover) {
        expect($entry)->toEqual(new Product('base:remote'))
            ->and($container->get('entry'))->toBe($entry);
    } else {
        expect($entry)->toBe($warm)
            ->and($container->get('candidate.entry'))->toBe('registered');
    }
})->with(['get' => true, 'has' => false])
    ->with(['new owner' => true, 'unaffected entry' => false]);

test('lookups that skip an active owner do not cache a dependent result', function (bool $duringRegistration, bool $factory): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':remote');
    $builder = (new ContainerBuilder())->addService('owner', $local);
    if ($factory) {
        $builder->addService('inspection.trigger', new Product('trigger'))->addDelegator('inspection.trigger', 'owner');
        $builder->addFactory('entry', static function (ContainerValue $value): Product {
            $owner = $value->container->get('owner');
            if (!$owner instanceof Closure) {
                throw new LogicException('Expected the selected callback.');
            }
            $entry = $owner(new Product('base'));
            if (!$entry instanceof Product) {
                throw new LogicException('Expected a decorated product.');
            }
            return $entry;
        });
    } else {
        $builder->addService('entry', new Product('base'))->addDelegator('entry', 'owner');
    }
    $container = $builder->build();
    $state = new class () {
        public bool $armed = false;
        public mixed $nested = null;
    };
    $owner = new OwnershipContainer(['owner' => $remote], static function (string $id) use ($container, $state): bool {
        if ($id !== 'owner') {
            return false;
        }
        if ($state->armed) {
            $state->armed = false;
            $state->nested = $container->get('entry');
        }
        return true;
    });
    $container->addContainer($owner);
    $state->armed = true;
    if ($duringRegistration) {
        $container->addContainer(new OwnershipContainer(['candidate.entry' => 'registered']));
    } else {
        expect($container->get('owner'))->toBe($remote);
    }

    expect($state->nested)->toEqual(new Product('base:local'));
    $entry = $container->get('entry');
    expect($entry)->toEqual(new Product('base:remote'))
        ->and($container->get('entry'))->toBe($entry);
})->with(['provider registration' => true, 'ordinary lookup' => false])
    ->with(['factory' => true, 'delegator' => false]);

test('a nested registration can change ownership that consulted the existing registry', function (bool $introducesFeature): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':remote');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $candidate = new OwnershipContainer([$introducesFeature ? 'feature' : 'unrelated' => true]);
    $state = new class () {
        public bool $armed = false;
        public mixed $nested = null;
    };
    $provider = new OwnershipContainer(['owner' => $remote], static function (string $id) use ($container, $candidate, $state): bool {
        if ($id !== 'owner') {
            return false;
        }
        if ($state->armed) {
            $state->armed = false;
            $container->addContainer($candidate);
            $state->nested = $container->get('entry');
        }
        return $container->has('feature');
    });
    $container->addContainer($provider);
    $state->armed = true;

    $container->addContainer($candidate);
    $resolved = $container->get('entry');

    expect($state->nested)->toEqual(new Product('base:local'))
        ->and($container->get('owner'))->toBe($introducesFeature ? $remote : $local)
        ->and($resolved)->toEqual(new Product($introducesFeature ? 'base:remote' : 'base:local'))
        ->and($container->get('entry'))->toBe($resolved);
})->with(['registration enables the earlier owner' => true, 'registration leaves ownership unchanged' => false]);

test('an attached container caches its result only after its dependency has a complete route', function (bool $provisional): void {
    $root = (new ContainerBuilder())->addService('source', 'local')->build();
    $attached = (new ContainerBuilder())
        ->addFactory('job', static function () use ($root): Product {
            $value = $root->get('source');
            if (!is_string($value)) {
                throw new LogicException('Expected a source name.');
            }
            return new Product($value);
        })
        ->build();
    $state = new class () {
        public bool $armed = false;
        public mixed $nested = null;
    };
    $root->addContainer(new OwnershipContainer(['source' => 'remote'], static function (string $id) use ($root, $state): bool {
        if ($id !== 'source') {
            return false;
        }
        if ($state->armed) {
            $state->armed = false;
            $state->nested = $root->get('job');
        }
        return true;
    }));
    $root->addContainer($attached);
    $state->armed = $provisional;

    $source = $root->get('source');
    $resolved = $root->get('job');

    expect($source)->toBe('remote')
        ->and($state->nested)->toEqual($provisional ? new Product('local') : null)
        ->and($resolved)->toEqual(new Product('remote'))
        ->and($attached->get('job'))->toBe($resolved);
})->with(['reentrant lookup' => true, 'ordinary lookup' => false]);

test('an incomplete route in one execution leaves a shared factory in another Fiber eligible for caching', function (): void {
    $root = (new ContainerBuilder())->addService('source', 'local')->build();
    $root->addContainer(new OwnershipContainer(['source' => 'remote'], static function (string $id) use ($root): bool {
        return $id === 'source' && $root->get('source') === 'local';
    }));
    $attached = (new ContainerBuilder())->addFactory('job', static function (): Product {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
        return new Product('complete');
    })->build();
    $root->addContainer($attached);
    $pending = new Fiber(static fn(): mixed => $root->get('job'));
    $pending->start();

    $source = $root->get('source');
    $pending->resume();

    expect($source)->toBe('remote')
        ->and($pending->getReturn())->toEqual(new Product('complete'))
        ->and($root->get('job'))->toBe($pending->getReturn());
});

function deferredProduct(Container $container, string $type): LazyProduct|ProxyProduct|Product
{
    $product = $container->get($type);
    return match (true) {
        $product instanceof InjectedProxy => $product->product,
        $product instanceof LazyProduct, $product instanceof ProxyProduct => $product,
        default => throw new LogicException('Expected a deferred product.'),
    };
}

test('a shared deferred object retries initialization after an incomplete external lookup', function (string $type, string $entryId, bool $prewarm): void {
    $container = (new ContainerBuilder())->addService('source', 'local')
        ->addFactory('proxy.backing', static function (ContainerValue $value): Product {
            $source = $value->container->get('source');
            if (!is_string($source)) {
                throw new LogicException('Expected a source name.');
            }
            return new Product($source);
        })->build();
    $state = new class () {
        public bool $armed = false;
        public bool $attempted = false;
        public LazyProduct|ProxyProduct|Product|null $entry = null;
    };
    $container->addContainer(new OwnershipContainer(['source' => 'remote'], static function (string $id) use ($container, $type, $entryId, $state): bool {
        if ($id !== 'source') {
            return false;
        }
        if ($state->armed) {
            $state->armed = false;
            $entry = $state->entry ??= deferredProduct($container, $type);
            expect(static fn(): string => $entry->value)->toThrow(ResolutionException::class, $entryId);
            $state->attempted = true;
        }
        return true;
    }));
    $state->entry = $prewarm ? deferredProduct($container, $type) : null;
    $state->armed = true;

    $source = $container->get('source');
    $resolved = deferredProduct($container, $type);

    expect($source)->toBe('remote')
        ->and($state->attempted)->toBeTrue()
        ->and($resolved)->toBe($state->entry)
        ->and($resolved->value)->toBe('remote');
})->with([
    'lazy ghost' => [LazyProduct::class, LazyProduct::class],
    'virtual proxy' => [ProxyProduct::class, ProxyProduct::class],
    'injected proxy' => [InjectedProxy::class, 'proxy.backing'],
])
    ->with(['published before the probe' => true, 'published during the probe' => false]);

test('an ownership notification invalidates a confirmation from a nested registration', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':remote');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $candidate = new OwnershipContainer(['candidate.entry' => 'registered']);
    $state = new class () {
        public bool $armed = false;
        public bool $owns = true;
        public ?OwnershipContainer $provider = null;
        public mixed $nested = null;
    };
    $provider = new OwnershipContainer(['owner' => $remote], static function (string $id) use ($container, $candidate, $state): bool {
        if ($id !== 'owner') {
            return false;
        }
        if ($state->armed) {
            $state->armed = false;
            $container->addContainer($candidate);
            $state->owns = false;
            $container->notifyExternalEntriesChanged($state->provider ?? throw new LogicException('Expected a registered provider.'), $id);
            $state->nested = $container->get('entry');
        }
        return $state->owns;
    });
    $state->provider = $provider;
    $container->addContainer($provider);
    $state->armed = true;

    $container->addContainer($candidate);

    $stored = $container->get('entry');
    expect($state->nested)->toEqual(new Product('base:local'))
        ->and($stored)->toEqual(new Product('base:local'))
        ->and($stored)->not->toBe($state->nested)
        ->and($container->get('entry'))->toBe($stored);
});

test('a registration ownership probe is isolated to its execution while suspended', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':remote');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $state = new class () {
        public bool $pause = false;
        public mixed $nested = null;
    };
    $existing = new OwnershipContainer(['owner' => $remote], static function (string $id) use ($container, $state): bool {
        if ($id !== 'owner') {
            return false;
        }
        if ($state->pause && Fiber::getCurrent() !== null) {
            $state->pause = false;
            Fiber::suspend('checking ownership');
            $state->nested = $container->get($id);
        }
        return true;
    });
    $container->addContainer($existing);
    $warm = $container->get('entry');
    $state->pause = true;
    $registration = new Fiber(static fn() => $container->addContainer(new OwnershipContainer(['candidate.entry' => 'registered'])));

    expect($registration->start())->toBe('checking ownership')
        ->and($container->get('owner'))->toBe($remote);
    $registration->resume();

    expect($state->nested)->toBe($local)
        ->and($container->get('entry'))->toBe($warm)
        ->and($container->get('candidate.entry'))->toBe('registered');
});

test('a failed registration releases its ownership probe before retry', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $remote = static fn(Product $entry): Product => new Product($entry->value . ':remote');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $state = new class () {
        public ?LogicException $failure = null;
    };
    $existing = new OwnershipContainer(['owner' => $remote], static function (string $id) use ($state): bool {
        if ($id !== 'owner') {
            return false;
        }
        if ($state->failure !== null) {
            throw $state->failure;
        }
        return true;
    });
    $container->addContainer($existing);
    $warm = $container->get('entry');
    $candidate = new OwnershipContainer(['candidate.entry' => 'registered']);
    $state->failure = new LogicException('Ownership inspection failed.');

    expect(fn() => $container->addContainer($candidate))->toThrow(InvalidConfigurationException::class)
        ->and($container->has('candidate.entry'))->toBeFalse();
    $state->failure = null;

    expect($container->get('owner'))->toBe($remote);
    $container->addContainer($candidate);
    expect($container->get('candidate.entry'))->toBe('registered')
        ->and($container->get('entry'))->toBe($warm);
});

test('external registration refreshes stale owner evidence after a nested membership change', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $existing = static fn(Product $entry): Product => new Product($entry->value . ':existing');
    $selected = static fn(Product $entry): Product => new Product($entry->value . ':selected');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $state = new class () {
        public bool $armed = false;
        public bool $owns = true;
    };
    $added = new OwnershipContainer(['nested.entry' => 'registered']);
    $earlier = new OwnershipContainer(['owner' => $existing], static function (string $id) use ($container, $added, $state): bool {
        if ($id !== 'owner') {
            return false;
        }
        $owned = $state->owns;
        if ($state->armed) {
            $state->armed = false;
            $container->addContainer($added);
            $state->owns = false;
        }
        return $owned;
    });
    $container->addContainer($earlier);
    $previous = $container->get('entry');
    $state->armed = true;

    $container->addContainer(new OwnershipContainer(['owner' => $selected]));
    $current = $container->get('entry');

    expect($previous)->toEqual(new Product('base:existing'))
        ->and($current)->toEqual(new Product('base:selected'))
        ->and($current)->not->toBe($previous)
        ->and($container->get('entry'))->toBe($current)
        ->and($container->get('owner'))->toBe($selected)
        ->and($container->get('nested.entry'))->toBe('registered');
});

test('a provider added during another dependency check can release an already inspected owner', function (bool $introducesFeature): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $original = static fn(Product $entry): Product => new Product($entry->value . ':original');
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $container = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'trigger' => new Product('trigger'),
        'owner' => $local,
        'trigger.owner' => $local,
    ])->addDelegator('entry', 'owner')->addDelegator('trigger', 'trigger.owner')->build();
    $addition = new OwnershipContainer([$introducesFeature ? 'feature' : 'unrelated' => true]);
    $state = new class () {
        public bool $armed = false;
        public int $candidateChecks = 0;
    };
    $existing = new OwnershipContainer(['owner' => $original], static function (string $id) use ($container, $addition, $state): bool {
        if ($id === 'trigger.owner' && $state->armed) {
            $state->armed = false;
            $container->addContainer($addition);
        }
        return $id === 'owner' && !$container->has('feature');
    });
    $candidate = new OwnershipContainer(['owner' => $replacement], static function (string $id) use ($state): bool {
        if ($id !== 'owner') {
            return false;
        }
        ++$state->candidateChecks;
        return true;
    });
    $container->addContainer($existing);
    $warm = $container->get('entry');
    $state->armed = true;

    $container->addContainer($candidate);

    $checksDuringRegistration = $state->candidateChecks;
    expect($container->get('owner'))->toBe($introducesFeature ? $replacement : $original)
        ->and($warm)->toEqual(new Product('base:original'));
    if ($introducesFeature) {
        expect($container->get('entry'))->toEqual(new Product('base:replacement'));
    } else {
        expect($container->get('entry'))->toBe($warm);
    }
    expect($checksDuringRegistration)->toBe($introducesFeature ? 1 : 0);
})->with(['registry-dependent owner releases the entry' => true, 'unrelated append preserves ownership' => false]);

test('external registration refreshes a candidate answer that used an earlier registry', function (bool $mounted, ?bool $readByValue, bool $appendInsideProbe): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $builder = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'trigger' => new Product('trigger'),
        'owner' => $local,
        'trigger.owner' => $local,
    ])->addDelegator('entry', 'owner')->addDelegator('trigger', 'trigger.owner');
    if ($readByValue === true) {
        $builder->addService('feature', false);
    }
    $container = $builder->build();
    if ($mounted) {
        $container->addContainer(new OwnershipContainer([]));
    }
    if ($readByValue === true) {
        expect($container->get('feature'))->toBeFalse();
    }
    $addition = new OwnershipContainer(['feature' => true]);
    $state = new class () {
        public int $candidateChecks = 0;
    };
    $candidate = new OwnershipContainer(['owner' => $replacement], static function (string $id) use ($container, $addition, $readByValue, $appendInsideProbe, $state): bool {
        if ($id === 'trigger.owner' && !$appendInsideProbe) {
            $container->addContainer($addition);
        }
        if ($id !== 'owner') {
            return false;
        }
        ++$state->candidateChecks;
        $owns = match ($readByValue) {
            true => $container->get('feature') === true,
            false => $container->has('feature'),
            null => false,
        };
        if ($appendInsideProbe) {
            $container->addContainer($addition);
        }
        return $owns;
    });
    $warm = $container->get('entry');

    $container->addContainer($candidate);

    $checksDuringRegistration = $state->candidateChecks;
    if ($readByValue !== null) {
        expect($container->get('owner'))->toBe($replacement)
            ->and($container->get('entry'))->toEqual(new Product('base:replacement'));
    } else {
        expect($container->get('owner'))->toBe($local)
            ->and($container->get('entry'))->toBe($warm);
    }
    expect($checksDuringRegistration)->toBe($readByValue === null ? 1 : 2);
})->with([
    'availability before first provider' => [false, false],
    'cached value before first provider' => [false, true],
    'availability with a mounted provider' => [true, false],
    'independent answer' => [false, null],
])->with(['append during another dependency' => false, 'append after reading inside the same probe' => true]);

test('external registration stops inspecting ownership once nested work registered its candidate', function (string $completion): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $selected = static fn(Product $entry): Product => new Product($entry->value . ':selected');
    $container = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'owner' => $local,
        'other' => new Product('other'),
        'other.owner' => $local,
    ])->addDelegator('entry', 'owner')->addDelegator('other', 'other.owner')->build();
    $state = new class () {
        public bool $armed = false;
        public bool $candidateInspected = false;
        public bool $completed = false;
        public ?OwnershipContainer $candidate = null;
        public ?Product $warmed = null;
    };
    $complete = static function () use ($container, $state): void {
        $state->armed = false;
        $container->addContainer($state->candidate ?? throw new LogicException('Expected the registration candidate.'));
        $entry = $container->get('entry');
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the decorated product.');
        }
        $state->warmed = $entry;
        $state->completed = true;
    };
    $candidate = new OwnershipContainer([
        'owner' => $selected,
        'candidate.entry' => 'registered',
    ], static function (string $id) use ($state, $complete, $completion): bool {
        if ($state->completed && ($id === 'owner' || $id === 'other.owner')) {
            throw new LogicException('Completed nested registration must not repeat candidate inspection.');
        }
        if ($id === 'owner') {
            $state->candidateInspected = true;
            if ($state->armed && $completion === 'candidate callback') {
                $complete();
            }
            return true;
        }
        return $id === 'candidate.entry';
    });
    $state->candidate = $candidate;
    $observer = new OwnershipContainer(['owner' => $selected], static function (string $id) use ($state, $complete, $completion): bool {
        if ($state->completed && ($id === 'owner' || $id === 'other.owner')) {
            throw new LogicException('Completed nested registration must not repeat existing-owner inspection.');
        }
        if ($id !== 'owner') {
            return false;
        }
        if ($completion === 'existing owner callback') {
            if ($state->armed) {
                $complete();
            }
            return true;
        }
        if ($completion === 'owner callback after candidate validation' && $state->armed && $state->candidateInspected) {
            $complete();
            return true;
        }
        return false;
    });
    $container->addContainer($observer);
    $unaffected = $container->get('other');
    $state->armed = true;

    $container->addContainer($candidate);

    expect($state->completed)->toBeTrue()
        ->and($state->warmed)->toEqual(new Product('base:selected'))
        ->and($container->get('entry'))->toBe($state->warmed)
        ->and($container->get('other'))->toBe($unaffected)
        ->and($container->get('candidate.entry'))->toBe('registered');
})->with([
    'existing owner callback',
    'candidate callback',
    'owner callback after candidate validation',
]);
