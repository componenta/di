<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalOwnershipNotification;

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
