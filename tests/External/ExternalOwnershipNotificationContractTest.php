<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\ExternalOwnershipNotificationContract;

use Closure;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $label) {}
}

final class Provider implements ContainerInterface
{
    /** @param array<string, Closure(Product):Product> $entries */
    public function __construct(public array $entries, public ?Closure $inspect = null) {}

    public function has(string $id): bool
    {
        ($this->inspect)?->__invoke($id);
        return isset($this->entries[$id]);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new NotFoundException($id);
    }
}

function decoration(string $label): Closure
{
    return static fn(Product $product): Product => new Product($label);
}

test('an external notification refreshes the affected delegator and preserves unrelated identity', function (): void {
    $di = (new ContainerBuilder())
        ->addServices(['first' => new Product('base'), 'second' => new Product('base')])
        ->addDelegator('first', 'first.owner')
        ->addDelegator('second', 'second.owner')
        ->build();
    $provider = new Provider(['first.owner' => decoration('old'), 'second.owner' => decoration('other')]);
    $di->addContainer($provider);
    $other = $di->get('second');
    expect($di->get('first'))->toEqual(new Product('old'));

    $provider->entries['first.owner'] = decoration('new');
    $di->notifyExternalEntriesChanged($provider, 'first.owner');

    expect($di->get('first'))->toEqual(new Product('new'))
        ->and($di->get('second'))->toBe($other);
});

test('external registration sees an earlier owner release a dependency during another ownership callback', function (): void {
    $di = (new ContainerBuilder())
        ->addServices(['first' => new Product('base'), 'second' => new Product('base'), 'first.owner' => decoration('local')])
        ->addDelegator('first', 'first.owner')
        ->addDelegator('second', 'second.owner')
        ->build();
    $earlier = new Provider(['first.owner' => decoration('old'), 'second.owner' => decoration('other')]);
    $di->addContainer($earlier);
    $other = $di->get('second');
    expect($di->get('first'))->toEqual(new Product('old'));
    $candidate = new Provider(['first.owner' => decoration('new')]);
    $earlier->inspect = static function (string $id) use ($di, $earlier): void {
        if ($id === 'second.owner') {
            $earlier->inspect = null;
            unset($earlier->entries['first.owner']);
            $di->notifyExternalEntriesChanged($earlier, 'first.owner');
            // Reentrant work can warm the temporary local result before publication.
            expect($di->get('first'))->toEqual(new Product('local'));
        }
    };

    $di->addContainer($candidate);

    expect($di->get('first'))->toEqual(new Product('new'))
        ->and($di->get('second'))->toBe($other);
});

test('a later provider registered during lookup preserves a cached delegator owned by the first provider', function (): void {
    $di = (new ContainerBuilder())
        ->addService('product', new Product('base'))
        ->addDelegator('product', 'owner')
        ->build();
    $original = decoration('original');
    $first = new Provider(['owner' => $original]);
    $di->addContainer($first);
    $warm = $di->get('product');

    $later = new Provider(['owner' => decoration('later')]);
    $first->inspect = static function (string $id) use ($di, $first, $later): void {
        if ($id === 'owner') {
            $first->inspect = null;
            $di->addContainer($later);
        }
    };

    expect($di->has('owner'))->toBeTrue()
        ->and($di->get('owner'))->toBe($original)
        ->and($di->get('product'))->toBe($warm);
});

test('a suspended delegator cannot publish a result from before an external notification', function (): void {
    $di = (new ContainerBuilder())
        ->addService('product', new Product('base'))
        ->addDelegator('product', 'owner')
        ->build();
    $provider = new Provider(['owner' => static function (Product $product): Product {
        Fiber::suspend('decorator selected');
        return new Product('old');
    }]);
    $di->addContainer($provider);
    $fiber = new Fiber(static fn(): mixed => $di->get('product'));
    expect($fiber->start())->toBe('decorator selected');

    $provider->entries['owner'] = decoration('new');
    $di->notifyExternalEntriesChanged($provider, 'owner');
    $fiber->resume();

    expect($fiber->getReturn())->toEqual(new Product('old'))
        ->and($di->get('product'))->toEqual(new Product('new'));
});

test('a provider can report an indirect ownership change after another provider is published', function (bool $ownsWithFeature): void {
    $di = (new ContainerBuilder())
        ->addServices(['product' => new Product('base'), 'unrelated' => new Product('other'), 'owner' => decoration('local')])
        ->addDelegator('product', 'owner')
        ->addDelegator('unrelated', decoration('unrelated'))
        ->build();
    $earlier = new Provider([]);
    $external = decoration('external');
    $earlier->inspect = static function (string $id) use ($di, $earlier, $external, $ownsWithFeature): void {
        if ($id !== 'owner') {
            return;
        }
        if ($di->has('feature') === $ownsWithFeature) {
            $earlier->entries[$id] = $external;
        } else {
            unset($earlier->entries[$id]);
        }
    };
    $di->addContainer($earlier);
    $before = $di->get('product');
    $unrelated = $di->get('unrelated');
    $di->addContainer(new Provider(['feature' => decoration('feature')]));

    $di->notifyExternalEntriesChanged($earlier, 'owner');

    expect($before)->toEqual(new Product($ownsWithFeature ? 'local' : 'external'))
        ->and($di->get('product'))->toEqual(new Product($ownsWithFeature ? 'external' : 'local'))
        ->and($di->get('unrelated'))->toBe($unrelated);
})->with(['earlier provider gains ownership' => true, 'earlier provider loses ownership' => false]);
