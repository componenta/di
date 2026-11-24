<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\PsrLookupFailureContract;

use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use DomainException;
use Fiber;
use LogicException;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

final readonly class Label
{
    public function __construct(#[EntryId('palette.label')] public string $value) {}
}

test('get distinguishes a missing requested entry from a missing dependency of a selected entry', function (): void {
    $container = (new ContainerBuilder())->addAlias('theme.label', Label::class)->build();
    expect($container->has('theme.label'))->toBeTrue()
        ->and($container->has('unknown.label'))->toBeFalse();

    $error = null;
    try {
        $container->get('theme.label');
    } catch (ResolutionException $failure) {
        $error = $failure;
    }

    if ($error === null) {
        throw new LogicException('Expected a service resolution failure.');
    }
    $cause = $error->getPrevious();
    if (!$cause instanceof NotFoundException) {
        throw new LogicException('Expected the original missing dependency.');
    }
    expect($error->serviceId)->toBe('theme.label')
        ->and($cause->id)->toBe('palette.label')
        ->and(fn() => $container->get('unknown.label'))->toThrow(NotFoundException::class, 'unknown.label')
        ->and(fn() => $container->make(Label::class))->toThrow(NotFoundException::class, 'palette.label');

    $container->set('palette.label', 'blue');
    expect($container->get('theme.label'))->toEqual(new Label('blue'));
});

test('get preserves a missing dependency of a selected delegator as its cause', function (): void {
    $container = (new ContainerBuilder())->addService('product', new \stdClass())->build();
    $container->delegator('product', static fn(): mixed => $container->get('missing.decorator.dependency'));

    expect($container->has('product'))->toBeTrue();
    try {
        $container->get('product');
    } catch (ResolutionException $error) {
        $cause = $error->getPrevious();
        Assert::assertInstanceOf(NotFoundException::class, $cause);
        expect($error->serviceId)->toBe('product')
            ->and($cause->id)->toBe('missing.decorator.dependency');
        return;
    }
    throw new LogicException('Expected the selected delegator to fail to resolve its dependency.');
});

#[Lazy, Proxy]
final class ConflictingEntry
{
    public string $value = 'never constructed';
}

test('an existing entry with invalid composition remains known to PSR-11 lookup', function (): void {
    $container = (new ContainerBuilder())->addAlias('conflicting', ConflictingEntry::class)->build();

    expect($container->has('conflicting'))->toBeTrue()
        ->and(fn() => $container->get('conflicting'))->toThrow(AttributeCompositionException::class)
        ->and($container->call(static fn(?ConflictingEntry $dependency = null): ?ConflictingEntry => $dependency))->toBeNull();
});

/** @param array<string|int,mixed> $params */
function ConflictingEntry(ContainerValue $container, array $params = []): string
{
    return 'native';
}

test('a native external container does not offer an invalid autowiring candidate to its parent', function (): void {
    $parent = new ContainerBuilder()->build();
    $provider = new ContainerBuilder()->build();
    $parent->addContainer($provider);
    $lookup = static fn(?ConflictingEntry $entry = null): ?ConflictingEntry => $entry;

    expect($parent->has(ConflictingEntry::class))->toBeTrue()
        ->and($parent->call($lookup))->toBeNull();
    $value = new ConflictingEntry();
    $parent->set(ConflictingEntry::class, $value);

    expect($parent->call($lookup))->toBe($value)
        ->and(fn() => $parent->get(ConflictingEntry::class))->toThrow(AttributeCompositionException::class);
});

#[Lazy, Proxy]
final readonly class InFlightNativeEntry
{
    public function __construct(public string $value) {}

    public function decorate(Label $entry): Label
    {
        return new Label($entry->value . ':' . $this->value);
    }
}

final class CountingForeignEntry implements ContainerInterface
{
    public private(set) int $entryChecks = 0;

    public function has(string $id): bool
    {
        if ($id !== InFlightNativeEntry::class) {
            return false;
        }
        ++$this->entryChecks;
        return true;
    }

    public function get(string $id): InFlightNativeEntry
    {
        if ($id !== InFlightNativeEntry::class) {
            throw NotFoundException::forService($id);
        }
        return new InFlightNativeEntry('external');
    }
}

test('registration uses the read mode for in-flight lookups and deferred delegates', function (string $provider): void {
    $di = (new ContainerBuilder())
        ->addServices([InFlightNativeEntry::class => new InFlightNativeEntry('local'), 'decorated' => new Label('entry')])
        ->addFactory('public.consumer', static function (ContainerValue $context): Label {
            $value = $context->container->get('public.source');
            Assert::assertIsString($value);
            return new Label($value);
        })
        ->addFactory('optional.consumer', static function (ContainerValue $context): Label {
            $value = $context->container->get('optional.source');
            Assert::assertIsString($value);
            return new Label($value);
        })
        ->build();
    $di->delegator('decorated', [InFlightNativeEntry::class, 'decorate']);
    $warm = $di->get('decorated');
    expect($warm)->toEqual(new Label('entry:local'));
    $di->addContainer(new class ($di) implements ContainerInterface {
        /** @var array<string, true> */
        private array $paused = [];

        public function __construct(private readonly Container $di) {}

        public function has(string $id): bool
        {
            return $id === 'public.source' || $id === 'optional.source';
        }

        public function get(string $id): string
        {
            if (!$this->has($id)) {
                throw NotFoundException::forService($id);
            }
            $entry = $id === 'public.source'
                ? $this->di->get(InFlightNativeEntry::class)
                : $this->di->call(static fn(?InFlightNativeEntry $entry = null): ?InFlightNativeEntry => $entry);
            if (!$entry instanceof InFlightNativeEntry) {
                throw new LogicException('Expected a native entry.');
            }
            if (!isset($this->paused[$id])) {
                $this->paused[$id] = true;
                Fiber::suspend($id);
            }
            return $entry->value;
        }
    });
    $public = new Fiber(static fn(): mixed => $di->get('public.consumer'));
    $optional = new Fiber(static fn(): mixed => $di->get('optional.consumer'));
    expect($public->start())->toBe('public.source')->and($optional->start())->toBe('optional.source');

    $candidate = $provider === 'foreign' ? new CountingForeignEntry() : new ContainerBuilder()->build();
    if ($provider === 'explicit' && $candidate instanceof Container) {
        $candidate->set(InFlightNativeEntry::class, new InFlightNativeEntry('external'));
    }
    $di->addContainer($candidate);
    if ($candidate instanceof CountingForeignEntry) {
        expect($candidate->entryChecks)->toBe(1);
    }
    $public->resume();
    $optional->resume();

    expect($public->getReturn())->toEqual(new Label('local'))
        ->and($optional->getReturn())->toEqual(new Label('local'));
    if ($provider !== 'implicit') {
        expect($di->get('public.consumer'))->toEqual(new Label('external'))
            ->and($di->get('optional.consumer'))->toEqual(new Label('external'))
            ->and($di->get('decorated'))->toEqual(new Label('entry:external'));
    } else {
        expect($di->get('optional.consumer'))->toBe($optional->getReturn())
            ->and($di->get('decorated'))->toBe($warm)
            ->and(fn() => $di->get(InFlightNativeEntry::class))->toThrow(AttributeCompositionException::class)
            ->and(fn() => $di->get('public.consumer'))->toThrow(AttributeCompositionException::class);
    }
})->with([
    'known but unresolvable native entry' => 'implicit',
    'explicit resolvable native entry' => 'explicit',
    'entry from an ordinary PSR provider' => 'foreign',
]);

test('a native owner can keep the PSR route when an ordinary provider takes over optional resolution', function (): void {
    $di = (new ContainerBuilder())
        ->addServices([InFlightNativeEntry::class => new InFlightNativeEntry('local'), 'decorated' => new Label('entry')])
        ->addFactory('consumer', static function (ContainerValue $context): Label {
            return new Label($context->container->get('source') === true ? 'known' : 'unknown');
        })
        ->build();
    $di->delegator('decorated', [InFlightNativeEntry::class, 'decorate']);
    $source = new class ($di) implements ContainerInterface {
        public private(set) int $entryChecks = 0;

        public function __construct(private readonly Container $di) {}

        public function has(string $id): bool
        {
            if ($id === InFlightNativeEntry::class) {
                ++$this->entryChecks;
            }
            return $id === 'source';
        }

        public function get(string $id): bool
        {
            if ($id !== 'source') {
                throw NotFoundException::forService($id);
            }
            $known = $this->di->has(InFlightNativeEntry::class);
            Fiber::suspend('source');
            return $known;
        }
    };
    $di->addContainer($source);
    $di->addContainer(new ContainerBuilder()->build());
    expect($di->get('decorated'))->toEqual(new Label('entry:local'));
    $flight = new Fiber(static fn(): mixed => $di->get('consumer'));
    expect($flight->start())->toBe('source');

    $candidate = new CountingForeignEntry();
    $before = $source->entryChecks;
    $di->addContainer($candidate);
    expect($candidate->entryChecks)->toBe(1)
        ->and($source->entryChecks - $before)->toBe(2);

    $flight->resume();
    expect($flight->getReturn())->toEqual(new Label('known'))
        ->and($di->get('consumer'))->toBe($flight->getReturn())
        ->and($di->get('decorated'))->toEqual(new Label('entry:external'))
        ->and(fn() => $di->get(InFlightNativeEntry::class))->toThrow(AttributeCompositionException::class);
});

test('an external native container proxy offers entries from its backing container', function (): void {
    $entry = new Label('external');
    $backing = new ContainerBuilder()->addService(Label::class, $entry)->build();
    $parent = new ContainerBuilder()->build();
    $provider = $parent->makeProxy(Container::class, static fn(): Container => $backing);
    if (!$provider instanceof Container) {
        throw new LogicException('Expected a container proxy.');
    }
    $parent->addContainer($provider);

    $lookup = static fn(?Label $label = null): ?Label => $label;
    expect($parent->call($lookup))->toBe($entry)
        ->and($parent->get(Label::class))->toBe($entry);
});

test('independent native container proxies resolve their own backing entries', function (): void {
    $first = new Label('first');
    $second = new Label('second');
    $allocator = new ContainerBuilder()->build();
    $parents = [];
    foreach ([$first, $second] as $entry) {
        $backing = new ContainerBuilder()->addService(Label::class, $entry)->build();
        $proxy = $allocator->makeProxy(Container::class, static fn(): Container => $backing);
        $parent = new ContainerBuilder()->build();
        $parent->addContainer($proxy);
        $parents[] = $parent;
    }
    $lookup = static fn(?Label $label = null): ?Label => $label;

    foreach ([0, 1, 0, 1] as $index) {
        $expected = $index === 0 ? $first : $second;
        expect($parents[$index]->call($lookup))->toBe($expected)
            ->and($parents[$index]->get(Label::class))->toBe($expected);
    }
});

test('get wraps an initialization failure of a native container proxy', function (): void {
    $owner = new ContainerBuilder()->build();
    $failure = new DomainException('backing container is unavailable');
    $proxy = $owner->makeProxy(Container::class, static fn(): never => throw $failure);
    if (!$proxy instanceof Container) {
        throw new LogicException('Expected a container proxy.');
    }

    try {
        $proxy->get('entry');
    } catch (ResolutionException $error) {
        expect($error->serviceId)->toBe('entry')
            ->and($error->getPrevious())->toBe($failure);
        return;
    }
    throw new LogicException('Expected a container resolution exception.');
});

test('an unavailable native provider proxy leaves a local autowiring route available', function (): void {
    $local = new Label('local');
    $owner = new ContainerBuilder()->addService(Label::class, $local)->build();
    $attempts = 0;
    $provider = $owner->makeProxy(Container::class, static function () use (&$attempts): never {
        ++$attempts;
        throw new DomainException('unavailable');
    });
    if (!$provider instanceof Container) {
        throw new LogicException('Expected a container proxy.');
    }
    $owner->addContainer($provider);

    $beforeLookup = $attempts;
    $value = $owner->call(static fn(?Label $value = null): ?Label => $value);

    expect($value)->toBe($local)
        ->and($attempts)->toBeGreaterThan($beforeLookup);
});

test('an invalid implicit entry does not mask a valid named function or factory', function (): void {
    $name = __NAMESPACE__ . '\\ConflictingEntry';
    $container = new ContainerBuilder()->addFactory('native.factory', $name)->build();

    expect($container->call($name))->toBe('native')
        ->and($container->get('native.factory'))->toBe('native');

    $container->set($name, static fn(): string => 'entry');
    expect($container->call($name))->toBe('entry');
});
