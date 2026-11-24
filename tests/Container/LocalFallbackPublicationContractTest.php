<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Container\LocalFallbackPublicationContract;

use Closure;
use Componenta\DI\Container;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Generator;
use LogicException;
use WeakReference;

final readonly class Product
{
    public function __construct(public string $from = 'reflection') {}
}

final readonly class Fallback implements EntryResolverInterface
{
    /** @param Closure():bool $probe */
    public function __construct(private Closure $probe) {}

    public function can(string $id): bool
    {
        return ($this->probe)();
    }

    public function resolve(string $id, EntryParameters $params): object
    {
        throw new LogicException('A superseded fallback must not construct an entry.');
    }
}

/** @return Generator<int,non-empty-string> */
function installLoader(Container $di, string $scenario, bool $declare, int &$calls): Generator
{
    $requested = __NAMESPACE__ . '\\Late_' . bin2hex(random_bytes(6));
    $loader = static function (string $id) use ($di, $scenario, $declare, &$calls, $requested): void {
        if ($id !== $requested) {
            return;
        }
        ++$calls;
        if ($scenario === 'service' || $scenario === 'unrelated') {
            $di->set($scenario === 'service' ? $requested : 'other', new Product('service'));
        } elseif ($scenario === 'definition') {
            $di->set($requested, Definition::factory(static fn(): Product => new Product('factory')));
        } elseif ($scenario === 'hide canonical') {
            $di->alias($requested, 'replacement');
        } elseif ($scenario === 'retarget alias') {
            $di->alias('first', 'replacement');
        }
        if ($declare) {
            class_alias(Product::class, $requested, false);
        }
    };
    spl_autoload_register($loader);
    try {
        yield $requested;
    } finally {
        spl_autoload_unregister($loader);
    }
}

test('a cold get selects a binding published while checking fallback availability', function (string $scenario, bool $declare): void {
    $di = (new ContainerBuilder())->build();
    $calls = 0;

    foreach (installLoader($di, $scenario, $declare, $calls) as $requested) {
        if ($scenario === 'unrelated' && !$declare) {
            expect(fn() => $di->get($requested))->toThrow(NotFoundException::class)
                ->and($di->get('other'))->toEqual(new Product('service'));
        } else {
            $first = $di->get($requested);
            $from = match ($scenario) {
                'definition' => 'factory',
                'service' => 'service',
                default => 'reflection',
            };
            expect($first)->toEqual(new Product($from))
                ->and($di->get($requested))->toBe($first);
        }
        expect($calls)->toBe(1);
    }
})->with(['service', 'definition', 'unrelated'])->with([true, false]);

test('a cold fallback product stays shared only while its canonical route remains available', function (bool $hideCanonical): void {
    $di = new ContainerBuilder()->build();
    $replacement = new Product('replacement');
    $di->set('replacement', $replacement);
    $calls = 0;

    foreach (installLoader($di, $hideCanonical ? 'hide canonical' : 'retarget alias', true, $calls) as $requested) {
        $di->alias('first', $requested);
        $di->alias('second', $requested);

        $earlier = $di->get('first');
        if (!$earlier instanceof Product) {
            throw new LogicException('Expected the in-flight fallback product.');
        }
        $reference = WeakReference::create($earlier);
        expect($earlier->from)->toBe('reflection');
        unset($earlier);

        expect($di->get('first'))->toBe($replacement)
            ->and($calls)->toBe(1);
        if ($hideCanonical) {
            expect($reference->get())->toBeNull()
                ->and($di->get('second'))->toBe($replacement);
        } else {
            expect($reference->get())->toBeInstanceOf(Product::class)
                ->and($di->get('second'))->toBe($reference->get());
        }
    }
})->with(['canonical becomes an alias' => true, 'only requested alias changes' => false]);

test('a cold has considers bindings published during fallback availability checks', function (string $scenario, bool $declare): void {
    $di = (new ContainerBuilder())->build();
    $calls = 0;

    foreach (installLoader($di, $scenario, $declare, $calls) as $requested) {
        expect($di->has($requested))->toBe($scenario !== 'unrelated' || $declare)
            ->and($calls)->toBe(1);
    }
})->with(['service', 'definition', 'unrelated'])->with([true, false]);

test('a cold make selects a newly registered definition and keeps ignoring shared overrides', function (string $scenario, bool $declare): void {
    $di = (new ContainerBuilder())->build();
    $calls = 0;

    foreach (installLoader($di, $scenario, $declare, $calls) as $requested) {
        if ($scenario === 'service' && !$declare) {
            expect(fn() => $di->make($requested))->toThrow(NotFoundException::class);
        } else {
            $first = $di->make($requested);
            expect($first)->toEqual(new Product($scenario === 'definition' ? 'factory' : 'reflection'))
                ->and($di->make($requested))->not->toBe($first);
        }
        expect($calls)->toBe(1);
    }
})->with(['service', 'definition'])->with([true, false]);

test('a composite stops probing fallbacks after the current probe registers an explicit binding', function (bool $availability, bool $claims): void {
    $di = (new ContainerBuilder())->build();
    $state = new class () {
        public ?CompositeResolver $resolver = null;
    };
    $first = new Fallback(static function () use ($state, $claims): bool {
        $resolver = $state->resolver ?? throw new LogicException('Expected a standalone composite.');
        $resolver->setDefinition('entry', Definition::factory(static fn(): Product => new Product('factory')));
        return $claims;
    });
    $later = new Fallback(static fn(): never => throw new LogicException('An explicit binding supersedes this probe.'));
    $resolver = new CompositeResolver(new FactoryResolver($di, new ProxyFactory()), $first, $later);
    $state->resolver = $resolver;

    if ($availability) {
        expect($resolver->can('entry'))->toBeTrue();
    } else {
        expect($resolver->resolve('entry'))->toEqual(new Product('factory'));
    }
})->with([true, false])->with([true, false]);

test('a newly published shared null stops the remaining fallback probes', function (bool $availability, bool $claims): void {
    $executor = (new ContainerBuilder())->build();
    $state = new class () {
        public ?Container $container = null;
    };
    $first = new Fallback(static function () use ($state, $claims): bool {
        $di = $state->container ?? throw new LogicException('Expected a container.');
        $di->set('entry', null);
        return $claims;
    });
    $later = new Fallback(static fn(): never => throw new LogicException('A shared value supersedes this probe.'));
    $di = new Container(new CompositeResolver($first, $later), $executor);
    $state->container = $di;

    if ($availability) {
        expect($di->has('entry'))->toBeTrue();
    } else {
        expect($di->get('entry'))->toBeNull();
    }
})->with([true, false])->with([true, false]);
