<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalProtectedOwnership;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\MapRequest;
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

    public function definition(string $name): object
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
    $container->set($id, MapRequest::class);
    $container->delegator($id, [$owner, 'definition']);
    $container->delegator($id, static fn(object $source): Product => new Product($source));
}

function product(Container $container, string $id = 'product'): Product
{
    $value = $container->get($id);
    return $value instanceof Product ? $value : throw new LogicException('Expected a wrapped lookup result.');
}

test('external registration preserves protected dependency pipelines without inspecting protected ownership', function (bool $alias, string $failure): void {
    $container = (new ContainerBuilder())->build();
    $owner = $alias ? 'protected.lookup' : AttributeDefinitionRegistry::class;
    if ($alias) {
        $container->alias($owner, AttributeDefinitionRegistry::class);
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
    $registry = $container->get(AttributeDefinitionRegistry::class);
    $marker = new \stdClass();
    $candidate = new External(['marker' => $marker], static function (string $id) use ($owner, $failure): bool {
        if ($id === $owner && $failure === 'candidate callback') {
            throw new LogicException('The new external cannot own the protected dependency.');
        }
        return $id === $owner || $id === 'marker';
    });

    $container->addContainer($candidate);

    expect(product($container))->toBe($previous)
        ->and($container->get(AttributeDefinitionRegistry::class))->toBe($registry)
        ->and($container->get('marker'))->toBe($marker)
        ->and($earlier->queries)->not->toContain($owner)
        ->and($candidate->queries)->not->toContain($owner);
})->with(['protected id' => false, 'alias to protected id' => true])
    ->with(['ownership claim', 'candidate callback', 'existing callback']);

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
            $container->alias('lookup', AttributeDefinitionRegistry::class);
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
        $container->alias('lookup', AttributeDefinitionRegistry::class);
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
        public bool $armed = false;
    };
    $triggerProbes = 0;
    $warmed = null;
    $earlier = new External([], static function (string $id) use ($container, $state, &$triggerProbes, &$warmed): bool {
        if ($state->armed && $id === 'trigger' && ++$triggerProbes === 2) {
            $state->armed = false;
            $container->alias('lookup', AttributeDefinitionRegistry::class);
            $warmed = product($container);
        }
        return false;
    });
    $container->addContainer($earlier);
    installProduct($container, 'product', 'lookup');
    installProduct($container, 'trigger.product', 'trigger');
    product($container);
    product($container, 'trigger.product');
    $replacement = new Lookup('external');
    $candidate = new External(['lookup' => $replacement, 'trigger' => $replacement]);
    $state->armed = true;

    $container->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);

    expect($warmed)->toBeInstanceOf(Product::class)
        ->and(product($container))->toBe($warmed)
        ->and(product($container, 'trigger.product')->source)->toBe($replacement)
        ->and($inspection['lookup'] ?? 0)->toBe(1)
        ->and($inspection['trigger'] ?? 0)->toBe(1);
});

test('a skipped protected alias is inspected once if a later candidate callback makes it public and warms it', function (): void {
    $local = new Lookup('local');
    $replacement = new Lookup('external');
    $container = (new ContainerBuilder())->addServices([
        'local.lookup' => $local,
        'trigger' => new Lookup('trigger local'),
    ])->build();
    $container->alias('lookup', AttributeDefinitionRegistry::class);
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
        public bool $armed = false;
        public bool $protected = false;
    };
    $triggerProbes = 0;
    $warmed = null;
    $earlier = new External([], static function (string $id) use ($container, $state, &$triggerProbes, &$warmed): bool {
        if ($id === 'lookup' && $state->protected) {
            throw new LogicException('Protected aliases do not need final owner callbacks.');
        }
        if ($state->armed && $id === 'trigger' && ++$triggerProbes === 2) {
            $state->armed = false;
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
            $container->alias('lookup', AttributeDefinitionRegistry::class);
            product($container);
        }
        return $id === 'lookup' || $id === 'trigger';
    });
    $state->armed = true;

    $container->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);

    expect($warmed)->toEqual(new Product($local))
        ->and(product($container)->source)->toBe($replacement)
        ->and(product($container))->not->toBe($warmed)
        ->and($inspection['lookup'] ?? 0)->toBe(1)
        ->and($inspection['trigger'] ?? 0)->toBe(1);
});

test('lookups stop external inspection when an earlier provider retargets an alias to protected core', function (bool $availability, bool $claims): void {
    $container = (new ContainerBuilder())->addService('local.lookup', new Lookup('local'))->build();
    $container->alias('lookup', 'local.lookup');
    $earlier = new External(['lookup' => new Lookup('external')], static function (string $id) use ($container, $claims): bool {
        if ($id === 'lookup') {
            $container->alias('lookup', AttributeDefinitionRegistry::class);
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
    $registry = $container->get(AttributeDefinitionRegistry::class);

    $result = $availability ? $container->has('lookup') : $container->get('lookup');

    expect($result)->toBe($availability ? true : $registry)
        ->and($earlier->queries)->toBe(['lookup'])
        ->and($later->queries)->toBe([]);
})->with(['get' => false, 'has' => true])
    ->with(['declined ownership' => false, 'claimed ownership' => true]);
