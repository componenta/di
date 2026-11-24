<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\ExternalNotificationProbeContract;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
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

#[Proxy]
final readonly class ProxyProduct
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

test('a provider retrieval uses the mutation state of its observed nested read for publication', function (string $consumer, string $change, bool $affected): void {
    $di = (new ContainerBuilder())
        ->addServices(['value' => 'before', 'retarget' => 'after'])
        ->addFactory('consumer', sourceProduct(...))
        ->addFactory('previously.shared', sourceProduct(...))
        ->build();
    if ($change === 'signed zero') {
        $di->set('value', 0.0);
    }
    if ($change === 'same decorated base' || $change === 'same base with canonical pipeline') {
        $decorations = 0;
        $di->alias('value', 'value.base');
        $di->set('value.base', 'before');
        $decoratedId = $change === 'same decorated base' ? 'value' : 'value.base';
        $di->delegator($decoratedId, static function () use (&$decorations): string {
            return ++$decorations === 1 ? 'before' : 'after';
        });
        if ($decoratedId === 'value.base') {
            expect($di->get($decoratedId))->toBe('before');
        }
    }
    $provider = new class ($di) implements ContainerInterface {
        public bool $pause = false;

        public function __construct(private readonly Container $di) {}

        public function has(string $id): bool
        {
            return $id === 'source';
        }

        public function get(string $id): string
        {
            if (!$this->has($id)) {
                throw NotFoundException::forService($id);
            }
            $value = $this->di->get('value');
            if (is_float($value)) {
                $value = fdiv(1.0, $value) < 0 ? 'after' : 'before';
            }
            if (!is_string($value)) {
                throw new LogicException('Expected the current value.');
            }
            if ($this->pause) {
                $this->pause = false;
                Fiber::suspend('read source');
            }
            return $value;
        }
    };
    $di->addContainer($provider);
    $shared = $di->get('previously.shared');
    expect($shared)->toEqual(new Product('before'));
    $deferred = $consumer === 'consumer' ? null : $di->get($consumer);
    if ($deferred !== null && !$deferred instanceof LazyProduct && !$deferred instanceof ProxyProduct) {
        throw new LogicException('Expected a deferred product.');
    }
    $provider->pause = true;
    $fiber = new Fiber(static fn(): mixed => $deferred === null ? $di->get('consumer') : $deferred->value);
    expect($fiber->start())->toBe('read source');

    $id = $affected ? 'value' : 'unrelated';
    if ($change === 'append') {
        $other = new Provider();
        $other->entries[$id] = 'after';
        $di->addContainer($other);
    } else {
        match ($change) {
            'set' => $di->set($id, 'after'),
            'alias' => $di->alias($id, 'retarget'),
            'delegator' => $di->delegator($id, static fn(): string => 'after'),
            'same scalar' => $di->set('value', 'before'),
            'same decorated base', 'same base with canonical pipeline' => $di->set('value.base', 'before'),
            'signed zero' => $di->set('value', -0.0),
            default => throw new LogicException('Expected a nested read mutation.'),
        };
    }

    if ($deferred !== null && $affected) {
        expect(static fn(): mixed => $fiber->resume())->toThrow(ResolutionException::class, 'external dependency lookup is incomplete');
    } else {
        $fiber->resume();
        expect($fiber->getReturn())->toEqual($deferred === null ? new Product('before') : 'before');
    }

    $current = $di->get($consumer);
    expect($di->get('source'))->toBe($affected ? 'after' : 'before')
        ->and($di->get('previously.shared'))->toBe($shared);
    if (!$current instanceof Product && !$current instanceof LazyProduct && !$current instanceof ProxyProduct) {
        throw new LogicException('Expected the selected product.');
    }
    expect($current->value)->toBe($affected ? 'after' : 'before')
        ->and($di->get($consumer))->toBe($current);
    if ($deferred !== null) {
        expect($current)->toBe($deferred);
    } else {
        expect($current === $fiber->getReturn())->toBe(!$affected);
    }
})->with(['factory' => 'consumer', 'ghost' => LazyProduct::class, 'proxy' => ProxyProduct::class])
    ->with([
        'replaced observed binding' => ['set', true],
        'replaced unrelated binding' => ['set', false],
        'retargeted observed binding' => ['alias', true],
        'retargeted unrelated binding' => ['alias', false],
        'decorated observed binding' => ['delegator', true],
        'decorated unrelated binding' => ['delegator', false],
        'registered observed owner' => ['append', true],
        'registered unrelated owner' => ['append', false],
        'republished the same scalar' => ['same scalar', false],
        'republished a scalar with a requested alias pipeline' => ['same decorated base', true],
        'republished a scalar with only a canonical pipeline' => ['same base with canonical pipeline', false],
        'changed the sign of zero' => ['signed zero', true],
    ]);

test('registration inspects only unfinished reads that a candidate could take over', function (string $scenario): void {
    $di = (new ContainerBuilder())
        ->addService('value', 'before')
        ->addFactory('consumer', sourceProduct(...))
        ->build();
    $earlier = new Provider();
    $earlier->entries = ['source' => 'before'];
    if ($scenario === 'current external owner') {
        $earlier->entries['value'] = 'before';
    }
    $di->addContainer($earlier);

    $fiber = null;
    if ($scenario !== 'ordinary registration') {
        $earlier->onGet = static function (string $id) use ($earlier, $di): void {
            if ($id === 'source') {
                $earlier->onGet = null;
                expect($di->get('value'))->toBe('before');
                Fiber::suspend('read source');
            }
        };
        $fiber = new Fiber(static fn(): mixed => $di->get('consumer'));
        expect($fiber->start())->toBe('read source');
    }

    $candidate = new Provider();
    $candidate->entries = ['value' => 'after', 'candidate.marker' => 'registered'];
    $cause = new LogicException('Expected the observed ownership probe to fail.');
    $calls = 0;
    $candidate->onHas = static function (string $id) use ($cause, &$calls): void {
        if ($id === 'value') {
            ++$calls;
            throw $cause;
        }
    };

    if ($scenario === 'ownership failure') {
        try {
            $di->addContainer($candidate);
            Assert::fail('A failed ownership check must prevent publication.');
        } catch (InvalidConfigurationException $error) {
            expect($error->getPrevious())->toBe($cause);
        }
        expect($calls)->toBe(1)
            ->and($di->has('candidate.marker'))->toBeFalse();
    } else {
        $di->addContainer($candidate);
        expect($calls)->toBe(0)
            ->and($di->get('candidate.marker'))->toBe('registered');
    }

    if ($fiber !== null) {
        $fiber->resume();
        expect($fiber->getReturn())->toEqual(new Product('before'))
            ->and($di->get('consumer'))->toBe($fiber->getReturn());
    }
})->with(['ordinary registration', 'current external owner', 'ownership failure']);

test('ownership probes do not add their own reads to the retrieval registration checklist', function (): void {
    $di = (new ContainerBuilder())
        ->addService('value', 'before')
        ->addFactory('consumer', sourceProduct(...))
        ->build();
    $candidate = new Provider();
    $candidate->entries = ['read.during.probe' => 'candidate value'];
    $checks = [];
    $candidate->onHas = static function (string $id) use ($di, &$checks): void {
        $checks[] = $id;
        if ($id === 'value') {
            expect($di->has('read.during.probe'))->toBeFalse();
        }
    };
    $source = new Provider();
    $source->entries = ['source' => 'before'];
    $source->onGet = static function (string $id) use ($di, $source, $candidate): void {
        if ($id === 'source') {
            $source->onGet = null;
            expect($di->get('value'))->toBe('before');
            $di->addContainer($candidate);
        }
    };
    $di->addContainer($source);

    $value = $di->get('consumer');

    expect($checks)->toBe(['value'])
        ->and($value)->toEqual(new Product('before'))
        ->and($di->get('consumer'))->toBe($value)
        ->and($di->get('read.during.probe'))->toBe('candidate value');
});
