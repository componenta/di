<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\ExternalProtectedOwnershipContract;

use Closure;
use Componenta\Config\Config;
use Componenta\DI\Container;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public object $source) {}
}

final readonly class Lookup
{
    public function __construct(public string $label) {}

    public function only(string $name): object
    {
        return $this;
    }
}

final class External implements ContainerInterface
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string,object> $entries
     * @param Closure(string):bool|null $onHas
     */
    public function __construct(private array $entries, private ?Closure $onHas = null) {}

    public function has(string $id): bool
    {
        $this->queries[] = $id;
        return $this->onHas === null ? array_key_exists($id, $this->entries) : ($this->onHas)($id);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new LogicException('Unexpected external lookup: ' . $id);
    }
}

function installProduct(Container $container, string $id, string $owner): void
{
    $container->set($id, 'selected');
    $container->delegator($id, [$owner, 'only']);
    $container->delegator($id, static fn(object $source): Product => new Product($source));
}

function product(Container $container, string $id = 'product'): Product
{
    $value = $container->get($id);
    return $value instanceof Product ? $value : throw new LogicException('Expected a wrapped lookup result.');
}

test('external registration preserves protected dependency pipelines without inspecting protected ownership', function (string $owner, string $failure): void {
    $container = (new ContainerBuilder())->build();
    if ($owner !== Config::class) {
        $container->alias($owner, Config::class);
    }
    $earlier = new External([], static function (string $id) use ($owner, $failure): bool {
        if ($id === $owner && $failure === 'existing callback') {
            throw new LogicException('An existing external cannot own the protected dependency.');
        }
        return false;
    });
    $container->addContainer($earlier);
    installProduct($container, 'product', $owner);
    $previous = product($container);
    $config = $container->get(Config::class);
    $marker = new \stdClass();
    $candidate = new External(['marker' => $marker], static function (string $id) use ($owner, $failure): bool {
        if ($id === $owner && $failure === 'candidate callback') {
            throw new LogicException('The new external cannot own the protected dependency.');
        }
        return $id === $owner || $id === 'marker';
    });

    $container->addContainer($candidate);

    expect(product($container))->toBe($previous)
        ->and($container->get(Config::class))->toBe($config)
        ->and($container->get('marker'))->toBe($marker)
        ->and($earlier->queries)->not->toContain($owner)
        ->and($candidate->queries)->not->toContain($owner);
})->with([
    'protected id' => Config::class,
    'alias to protected id' => 'protected.lookup',
    'numeric string alias' => '7',
])
    ->with(['ownership claim', 'candidate callback', 'existing callback']);

test('external callbacks can read an alias after retargeting it to protected core', function (bool $availability, bool $claims): void {
    $container = (new ContainerBuilder())->addService('local.lookup', new Lookup('local'))->build();
    $container->alias('lookup', 'local.lookup');
    $config = $container->get(Config::class);
    $observed = new class () {
        public ?bool $available = null;
        public mixed $value = null;
    };
    $external = new External([], static function (string $id) use ($container, $observed, $claims): bool {
        if ($id !== 'lookup') {
            return false;
        }
        $container->alias('lookup', Config::class);
        $observed->available = $container->has('lookup');
        $observed->value = $container->get('lookup');
        return $claims;
    });
    $container->addContainer($external);

    $result = $availability ? $container->has('lookup') : $container->get('lookup');

    expect($observed->available)->toBeTrue()
        ->and($observed->value)->toBe($config)
        ->and($result)->toBe($availability ? true : $config)
        ->and($external->queries)->toBe(['lookup']);
})->with(['get' => false, 'has' => true])
    ->with(['declined ownership' => false, 'claimed ownership' => true]);

test('registration rechecks protection between existing ownership callbacks and before candidate inspection', function (bool $claims): void {
    $container = (new ContainerBuilder())->addService('local.lookup', new Lookup('local'))->build();
    $container->alias('lookup', 'local.lookup');
    $state = new class () {
        public bool $armed = false;
        public bool $protected = false;
    };
    $warmed = null;
    $earlier = new External([], static function (string $id) use ($container, $claims, $state, &$warmed): bool {
        if ($id === 'lookup' && $state->armed) {
            $state->armed = false;
            $state->protected = true;
            $container->alias('lookup', Config::class);
            $warmed = product($container);
            return $claims;
        }
        return false;
    });
    $container->addContainer($earlier);
    $later = new External([], static function (string $id) use ($state): bool {
        if ($id === 'lookup' && $state->protected) {
            throw new LogicException('Later existing providers cannot inspect a protected alias.');
        }
        return false;
    });
    $container->addContainer($later);
    installProduct($container, 'product', 'lookup');
    $previous = product($container);
    $later->queries = [];
    $candidate = new External([], static function (string $id): bool {
        if ($id === 'lookup') {
            throw new LogicException('The alias became protected before candidate inspection.');
        }
        return false;
    });
    $state->armed = true;

    $container->addContainer($candidate);

    expect($warmed)->toBeInstanceOf(Product::class)
        ->and($warmed)->not->toBe($previous)
        ->and(product($container))->toBe($warmed)
        ->and($later->queries)->not->toContain('lookup')
        ->and($candidate->queries)->not->toContain('lookup');
})->with(['declined ownership' => false, 'claimed ownership' => true]);

test('registration preserves a protected alias warmed by its candidate ownership callback', function (): void {
    $container = (new ContainerBuilder())->addService('local.lookup', new Lookup('local'))->build();
    $container->alias('lookup', 'local.lookup');
    $state = new class () {
        public bool $protected = false;
    };
    $earlier = new External([], static function (string $id) use ($state): bool {
        if ($id === 'lookup' && $state->protected) {
            throw new LogicException('Final ownership proof must skip the now-protected alias.');
        }
        return false;
    });
    $container->addContainer($earlier);
    installProduct($container, 'product', 'lookup');
    product($container);
    $warmed = null;
    $candidate = new External([], static function (string $id) use ($container, $state, &$warmed): bool {
        if ($id !== 'lookup') {
            return false;
        }
        $state->protected = true;
        $container->alias('lookup', Config::class);
        $warmed = product($container);
        return true;
    });

    $container->addContainer($candidate);

    expect(product($container))->toBe($warmed)
        ->and(array_count_values($candidate->queries)['lookup'] ?? 0)->toBe(1);
});

test('later ownership proof cannot invalidate an earlier alias that became protected and was rewarmed', function (): void {
    $container = (new ContainerBuilder())->addServices([
        'local.lookup' => new Lookup('local'),
        'trigger' => new Lookup('trigger local'),
    ])->build();
    $container->alias('lookup', 'local.lookup');
    $state = new class () {
        public bool $candidateInspectedLookup = false;
        public bool $existingRecheckedLookup = false;
        public ?Product $warmed = null;
    };
    $earlier = new External([], static function (string $id) use ($container, $state): bool {
        if ($id === 'lookup' && $state->candidateInspectedLookup) {
            $state->existingRecheckedLookup = true;
        }
        if ($id === 'trigger' && $state->existingRecheckedLookup && $state->warmed === null) {
            $container->alias('lookup', Config::class);
            $state->warmed = product($container);
        }
        return false;
    });
    $container->addContainer($earlier);
    installProduct($container, 'product', 'lookup');
    installProduct($container, 'trigger.product', 'trigger');
    product($container);
    product($container, 'trigger.product');
    $replacement = new Lookup('external');
    $candidate = new External(['lookup' => $replacement, 'trigger' => $replacement], static function (string $id) use ($state): bool {
        if ($id === 'lookup') {
            $state->candidateInspectedLookup = true;
        }
        return $id === 'lookup' || $id === 'trigger';
    });

    $container->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);

    expect($state->warmed)->toBeInstanceOf(Product::class)
        ->and(product($container))->toBe($state->warmed)
        ->and(product($container, 'trigger.product')->source)->toBe($replacement)
        ->and($inspection['lookup'] ?? 0)->toBe(1)
        ->and($inspection['trigger'] ?? 0)->toBe(1);
});

test('final ownership proof continues to later public dependencies after protecting its current alias', function (): void {
    $container = (new ContainerBuilder())->addServices([
        'local.lookup' => new Lookup('local'),
        'second.lookup' => new Lookup('second local'),
    ])->build();
    $container->alias('lookup', 'local.lookup');
    $state = new class () {
        public bool $candidateInspectedLookup = false;
        public ?Product $warmed = null;
    };
    $earlier = new External([], static function (string $id) use ($container, $state): bool {
        if ($id === 'lookup' && $state->candidateInspectedLookup && $state->warmed === null) {
            $container->alias('lookup', Config::class);
            $state->warmed = product($container);
        }
        return false;
    });
    $container->addContainer($earlier);
    installProduct($container, 'product', 'lookup');
    installProduct($container, 'second.product', 'second.lookup');
    product($container);
    $previous = product($container, 'second.product');
    $replacement = new Lookup('external');
    $candidate = new External(['lookup' => $replacement, 'second.lookup' => $replacement], static function (string $id) use ($state): bool {
        if ($id === 'lookup') {
            $state->candidateInspectedLookup = true;
        }
        return $id === 'lookup' || $id === 'second.lookup';
    });

    $container->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);
    $current = product($container, 'second.product');

    expect($state->warmed)->toBeInstanceOf(Product::class)
        ->and(product($container))->toBe($state->warmed)
        ->and($current)->not->toBe($previous)
        ->and($current->source)->toBe($replacement)
        ->and($inspection['lookup'] ?? 0)->toBe(1)
        ->and($inspection['second.lookup'] ?? 0)->toBe(1);
});

test('a skipped protected alias is inspected once if a later candidate callback makes it public and warms it', function (): void {
    $local = new Lookup('local');
    $replacement = new Lookup('external');
    $container = (new ContainerBuilder())->addServices([
        'local.lookup' => $local,
        'trigger' => new Lookup('trigger local'),
    ])->build();
    $container->alias('lookup', Config::class);
    installProduct($container, 'product', 'lookup');
    installProduct($container, 'trigger.product', 'trigger');
    product($container);
    $public = false;
    $warmed = null;
    $candidate = new External(['lookup' => $replacement], static function (string $id) use ($container, &$public, &$warmed): bool {
        if ($id === 'trigger') {
            $public = true;
            $container->alias('lookup', 'local.lookup');
            $warmed = product($container);
            return false;
        }
        if ($id === 'lookup' && !$public) {
            throw new LogicException('The alias is still protected.');
        }
        return $id === 'lookup';
    });

    $container->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);

    expect($warmed)->toEqual(new Product($local))
        ->and(product($container)->source)->toBe($replacement)
        ->and(product($container))->not->toBe($warmed)
        ->and($inspection['lookup'] ?? 0)->toBe(1)
        ->and($inspection['trigger'] ?? 0)->toBe(1);
});

test('final proof revisits an alias that becomes public again without replaying candidate validation', function (): void {
    $local = new Lookup('local');
    $replacement = new Lookup('external');
    $container = (new ContainerBuilder())->addServices([
        'local.lookup' => $local,
        'trigger' => new Lookup('trigger local'),
    ])->build();
    $container->alias('lookup', 'local.lookup');
    $state = new class () {
        public bool $protected = false;
        public bool $candidateInspectedTrigger = false;
    };
    $warmed = null;
    $earlier = new External([], static function (string $id) use ($container, $state, &$warmed): bool {
        if ($id === 'lookup' && $state->protected) {
            throw new LogicException('Protected aliases do not need final owner callbacks.');
        }
        if ($id === 'trigger' && $state->protected && $state->candidateInspectedTrigger) {
            $state->protected = false;
            $container->alias('lookup', 'local.lookup');
            $warmed = product($container);
        }
        return false;
    });
    $container->addContainer($earlier);
    installProduct($container, 'product', 'lookup');
    installProduct($container, 'trigger.product', 'trigger');
    product($container);
    $protectOnce = true;
    $candidate = new External(['lookup' => $replacement, 'trigger' => $replacement], static function (string $id) use ($container, $state, &$protectOnce): bool {
        if ($id === 'lookup' && $protectOnce) {
            $protectOnce = false;
            $state->protected = true;
            $container->alias('lookup', Config::class);
            product($container);
        }
        if ($id === 'trigger') {
            $state->candidateInspectedTrigger = true;
        }
        return $id === 'lookup' || $id === 'trigger';
    });

    $container->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);

    expect($warmed)->toEqual(new Product($local))
        ->and(product($container)->source)->toBe($replacement)
        ->and(product($container))->not->toBe($warmed)
        ->and($inspection['lookup'] ?? 0)->toBe(1)
        ->and($inspection['trigger'] ?? 0)->toBe(1);
});

test('registration rechecks a candidate miss that protected its alias before it reopened', function (bool $ownsAfterReopening): void {
    $local = new Lookup('local');
    $external = new Lookup('external');
    $container = (new ContainerBuilder())->addServices([
        'local.lookup' => $local,
        'trigger' => new Lookup('trigger'),
    ])->build();
    $container->alias('lookup', 'local.lookup');
    installProduct($container, 'product', 'lookup');
    installProduct($container, 'trigger.product', 'trigger');
    expect(product($container)->source)->toBe($local);
    $state = new class () {
        public bool $protectedAlias = false;
        public ?Product $warmed = null;
    };
    $candidate = new External(['lookup' => $external], static function (string $id) use ($container, $state, $ownsAfterReopening): bool {
        if ($id === 'lookup') {
            if (!$state->protectedAlias) {
                $state->protectedAlias = true;
                $container->alias('lookup', Config::class);
                return false;
            }
            return $state->warmed !== null && $ownsAfterReopening;
        }
        if ($id === 'trigger' && $state->protectedAlias && $state->warmed === null) {
            $container->alias('lookup', 'local.lookup');
            $state->warmed = product($container);
        }
        return false;
    });

    $container->addContainer($candidate);

    $checks = array_count_values($candidate->queries);
    $current = product($container);
    expect($state->warmed?->source)->toBe($local)
        ->and($container->get('lookup'))->toBe($ownsAfterReopening ? $external : $local)
        ->and($current->source)->toBe($ownsAfterReopening ? $external : $local)
        ->and($current === $state->warmed)->toBe(!$ownsAfterReopening)
        ->and(product($container))->toBe($current)
        ->and($checks['lookup'] ?? 0)->toBe(2)
        ->and($checks['trigger'] ?? 0)->toBe(1);
})->with(['new owner' => true, 'stable miss' => false]);

test('lookups stop external inspection when an earlier provider retargets an alias to protected core', function (bool $availability, bool $claims): void {
    $container = (new ContainerBuilder())->addService('local.lookup', new Lookup('local'))->build();
    $container->alias('lookup', 'local.lookup');
    $earlier = new External(['lookup' => new Lookup('external')], static function (string $id) use ($container, $claims): bool {
        if ($id === 'lookup') {
            $container->alias('lookup', Config::class);
            return $claims;
        }
        return false;
    });
    $later = new External([], static function (string $id): bool {
        if ($id === 'lookup') {
            throw new LogicException('The previous provider already made this alias protected.');
        }
        return false;
    });
    $container->addContainer($earlier);
    $container->addContainer($later);
    $config = $container->get(Config::class);

    $result = $availability ? $container->has('lookup') : $container->get('lookup');

    expect($result)->toBe($availability ? true : $config)
        ->and($earlier->queries)->toBe(['lookup'])
        ->and($later->queries)->toBe([]);
})->with(['get' => false, 'has' => true])
    ->with(['declined ownership' => false, 'claimed ownership' => true]);
