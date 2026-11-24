<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\ExternalNotificationProbeContract;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use PHPUnit\Framework\Assert;
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

final class Provider implements ContainerInterface
{
    /** @var array<string,string> */
    public array $entries = [];
    /** @var (Closure(string):void)|null */
    public ?Closure $onHas = null;
    /** @var (Closure(string):void)|null */
    public ?Closure $onGet = null;

    public function has(string $id): bool
    {
        $owns = array_key_exists($id, $this->entries);
        ($this->onHas)?->__invoke($id);
        return $owns;
    }

    public function get(string $id): string
    {
        $value = $this->entries[$id] ?? throw NotFoundException::forService($id);
        ($this->onGet)?->__invoke($id);
        return $value;
    }
}

test('a deferred initializer is affected only by a notification for an already read ID', function (
    string $dependency,
    string $changedId,
    bool $readBeforeChange,
): void {
    $di = (new ContainerBuilder())->build();
    $provider = new Provider();
    $provider->entries = ['source' => 'external', 'feature' => 'enabled', 'unrelated' => 'lease'];
    $di->addContainer($provider);
    $deferred = $di->get(LazyProduct::class);
    $provider->onHas = static function (string $id) use ($di, $provider, $dependency, $changedId, $readBeforeChange): void {
        if ($id !== 'source') {
            return;
        }
        $provider->onHas = null;
        if ($readBeforeChange) {
            expect($di->has($dependency))->toBeTrue();
        }
        unset($provider->entries[$changedId]);
        $di->notifyExternalEntriesChanged($provider, $changedId);
        if (!$readBeforeChange) {
            expect($di->has($dependency))->toBeFalse();
        }
    };

    Assert::assertInstanceOf(LazyProduct::class, $deferred);
    if ($readBeforeChange && $dependency === $changedId) {
        expect(static fn(): string => $deferred->value)->toThrow(ResolutionException::class, 'external dependency lookup is incomplete');
    }
    expect($deferred->value)->toBe('external')
        ->and($di->get(LazyProduct::class))->toBe($deferred);
})->with([
    'reads core before an unrelated change' => [Config::class, 'unrelated', true],
    'reads an external feature before an unrelated change' => ['feature', 'unrelated', true],
    'reads a changed feature before notification' => ['feature', 'feature', true],
    'reads a changed feature after notification' => ['feature', 'feature', false],
]);

function sourceProduct(ContainerValue $value): Product
{
    $source = $value->container->get('source');
    if (!is_string($source)) {
        throw new LogicException('Expected a string source.');
    }
    return new Product($source);
}

test('a notified ownership lookup cannot share its pre-notification fallback', function (bool $availabilityOnly): void {
    $builder = new ContainerBuilder();
    $factory = $availabilityOnly
        ? static fn(ContainerValue $value): Product => new Product($value->container->has('source') ? 'external' : 'local')
        : sourceProduct(...);
    if (!$availabilityOnly) {
        $builder->addService('source', 'local');
    }
    $di = $builder->addFactory('consumer', $factory)
        ->addFactory('previously.shared', $factory)
        ->build();
    $provider = new Provider();
    $di->addContainer($provider);
    $previouslyShared = $di->get('previously.shared');
    $provider->onHas = static function (string $id) use ($provider): void {
        if ($id === 'source') {
            $provider->onHas = null;
            Fiber::suspend('checked ownership');
        }
    };

    $fiber = new Fiber(static fn(): mixed => $di->get('consumer'));
    expect($fiber->start())->toBe('checked ownership');
    $provider->entries['source'] = 'external';
    $di->notifyExternalEntriesChanged($provider, 'source');
    $fiber->resume();

    expect($fiber->getReturn())->toEqual(new Product('local'));
    $fresh = $di->get('consumer');
    expect($fresh)->toEqual(new Product('external'))
        ->and($di->get('consumer'))->toBe($fresh)
        ->and($di->get('previously.shared'))->toBe($previouslyShared);
})->with(['get' => false, 'has' => true]);

test('provider retrieval is superseded only by a matching external notification', function (bool $affectsSource): void {
    $di = (new ContainerBuilder())
        ->addFactory('consumer', sourceProduct(...))
        ->build();
    $provider = new Provider();
    $provider->entries = ['source' => 'before'];
    $di->addContainer($provider);
    $provider->onGet = static function (string $id) use ($provider): void {
        if ($id === 'source') {
            $provider->onGet = null;
            Fiber::suspend('retrieved value');
        }
    };

    $fiber = new Fiber(static fn(): mixed => $di->get('consumer'));
    expect($fiber->start())->toBe('retrieved value');
    $changedId = $affectsSource ? 'source' : 'unrelated';
    $provider->entries[$changedId] = 'after';
    $di->notifyExternalEntriesChanged($provider, $changedId);
    $fiber->resume();

    expect($fiber->getReturn())->toEqual(new Product('before'));
    $current = $di->get('consumer');
    expect($current)->toEqual(new Product($affectsSource ? 'after' : 'before'))
        ->and($di->get('consumer'))->toBe($current);
    if (!$affectsSource) {
        expect($current)->toBe($fiber->getReturn());
    }
})->with(['matching ID' => true, 'unrelated ID' => false]);
