<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Container\ColdGetPublishedRouteContract;

use Closure;
use Componenta\DI\Container;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

final class LookupFixture
{
    public Container $container;
}

final readonly class Fallback implements EntryResolverInterface
{
    /**
     * @param Closure(string):bool $inspect
     * @param Closure():mixed $create
     */
    public function __construct(private Closure $inspect, private Closure $create) {}

    public function can(string $id): bool
    {
        return ($this->inspect)($id);
    }

    public function resolve(string $id, EntryParameters $params): mixed
    {
        return ($this->create)();
    }
}

final readonly class Provider implements ContainerInterface
{
    /**
     * @param Closure():bool $inspect
     * @param Closure():mixed $read
     */
    public function __construct(private Closure $inspect, private Closure $read) {}

    public function has(string $id): bool
    {
        return $id === 'service' && ($this->inspect)();
    }

    public function get(string $id): mixed
    {
        if ($id !== 'service') {
            throw NotFoundException::forService($id);
        }
        return ($this->read)();
    }
}

test('a cold get inspects the providers published during a local miss', function (bool $earlier, bool $owns): void {
    $fixture = new LookupFixture();
    $trace = [];
    $remote = new Provider(static function () use (&$trace, $owns): bool {
        $trace[] = 'new provider';
        return $owns;
    }, static function () use (&$trace): null {
        $trace[] = 'retrieve';
        return null;
    });
    $fallback = new Fallback(
        static function (string $id) use ($fixture, &$trace, $remote): bool {
            if ($id === 'service') {
                $trace[] = 'local miss';
                $fixture->container->addContainer($remote);
            }
            return false;
        },
        static fn(): never => throw new \LogicException('No fallback was selected.'),
    );
    $fixture->container = new Container($fallback, new ContainerBuilder()->build());
    $expected = [];
    if ($earlier) {
        $fixture->container->addContainer(new Provider(
            static function () use (&$trace): bool {
                $trace[] = 'earlier miss';
                return false;
            },
            static fn(): never => throw new \LogicException('The first provider does not own the entry.'),
        ));
        $expected[] = 'earlier miss';
    }
    $expected[] = 'local miss';
    $expected[] = 'new provider';

    if ($owns) {
        expect($fixture->container->get('service'))->toBeNull();
        $expected[] = 'retrieve';
    } else {
        try {
            $fixture->container->get('service');
            throw new \LogicException('Expected a missing entry.');
        } catch (NotFoundException $error) {
            expect($error->id)->toBe('service')->and($error->getPrevious())->toBeNull();
        }
    }
    expect($trace)->toBe($expected);
})->with(['first provider' => false, 'earlier registered miss' => true])
    ->with(['new owner' => true, 'no owner' => false]);

test('get follows a retargeted alias only if the previous fallback declined', function (bool $oldOwner): void {
    $fixture = new LookupFixture();
    $events = [];
    $fallback = new Fallback(static function (string $id) use ($fixture, &$events, $oldOwner): bool {
        $events[] = $id;
        $fixture->container->alias('service', 'current');
        return $oldOwner;
    }, static fn(): string => 'previously selected');
    $fixture->container = new Container(
        $fallback,
        new ContainerBuilder()->build(),
        aliases: ['service' => 'old'],
        services: ['current' => 'ready'],
    );

    expect($fixture->container->get('service'))->toBe($oldOwner ? 'previously selected' : 'ready')
        ->and($events)->toBe(['old'])
        ->and($fixture->container->get('service'))->toBe('ready');
})->with(['declined' => false, 'selected' => true]);

test('a selected local resolution failure retains its cause after mounting a provider', function (): void {
    $fixture = new LookupFixture();
    $error = NotFoundException::forService('dependency');
    $remote = new Provider(static fn(): never => throw new \LogicException('Do not probe after a selected failure.'), static fn(): mixed => null);
    $fallback = new Fallback(static function (string $id) use ($fixture, $remote): bool {
        $fixture->container->addContainer($remote);
        return true;
    }, static fn(): never => throw $error);
    $fixture->container = new Container($fallback, new ContainerBuilder()->build());

    try {
        $fixture->container->get('service');
        throw new \LogicException('Expected resolution failure.');
    } catch (ResolutionException $failure) {
        expect($failure->serviceId)->toBe('service')->and($failure->getPrevious())->toBe($error);
    }
});

test('an appended owner retrieval failure is never misreported as a missing requested ID', function (): void {
    $fixture = new LookupFixture();
    $cause = NotFoundException::forService('external dependency');
    $remote = new Provider(static fn(): bool => true, static fn(): never => throw $cause);
    $fallback = new Fallback(static function (string $id) use ($fixture, $remote): bool {
        $fixture->container->addContainer($remote);
        return false;
    }, static fn(): never => throw new \LogicException('No fallback was selected.'));
    $fixture->container = new Container($fallback, new ContainerBuilder()->build());

    try {
        $fixture->container->get('service');
        throw new \LogicException('Expected resolution failure.');
    } catch (ResolutionException $failure) {
        expect($failure->serviceId)->toBe('service')->and($failure->getPrevious())->toBe($cause);
    }
});

test('an appended provider can inspect the same ID after the old local get finishes', function (): void {
    $fixture = new LookupFixture();
    $external = new Provider(
        static function () use ($fixture): bool {
            expect(fn() => $fixture->container->get('service'))->toThrow(NotFoundException::class, 'service');
            return true;
        },
        static fn(): string => 'external',
    );
    $fallback = new Fallback(static function (string $id) use ($fixture, $external): bool {
        $fixture->container->addContainer($external);
        return false;
    }, static fn(): never => throw new \LogicException('No local route is available.'));
    $fixture->container = new Container($fallback, new ContainerBuilder()->build());

    expect($fixture->container->get('service'))->toBe('external');
});

test('a selected local null completes the original route without probing an appended provider', function (): void {
    $fixture = new LookupFixture();
    $external = new Provider(
        static fn(): never => throw new \LogicException('The selected local route must complete.'),
        static fn(): null => null,
    );
    $fallback = new Fallback(static function (string $id) use ($fixture, $external): bool {
        $fixture->container->addContainer($external);
        return true;
    }, static fn(): null => null);
    $fixture->container = new Container($fallback, new ContainerBuilder()->build());

    expect($fixture->container->get('service'))->toBeNull();
});

test('an unowned suffix can establish a local entry without replaying the old fallback', function (string $kind): void {
    $di = new ContainerBuilder()->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $di->alias('service', $legacy);
    $di->set('current', null);
    $calls = 0;
    $external = new Provider(
        static function () use ($di, $kind): bool {
            if ($kind === 'definition') {
                $di->set('service', \Componenta\DI\Definition\Definition::factory(static fn(): null => null));
            } elseif ($kind === 'alias') {
                $di->alias('service', 'current');
            } else {
                $di->set('service', null);
            }
            return false;
        },
        static fn(): never => throw new \LogicException('The provider does not own the entry.'),
    );
    $loader = static function (string $name) use ($di, $legacy, $external, &$calls): void {
        if ($name === $legacy) {
            ++$calls;
            $di->addContainer($external);
        }
    };
    spl_autoload_register($loader);

    try {
        expect($di->get('service'))->toBeNull()->and($calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['definition', 'alias', 'shared']);

test('lookup reports a cycle when fallback callbacks repeatedly change the same route', function (bool $read): void {
    $fixture = new LookupFixture();
    $inspected = [];
    $fallback = new Fallback(
        static function (string $id) use ($fixture, &$inspected): bool {
            $inspected[] = $id;
            if (count($inspected) > 4) {
                throw new \LogicException('The lookup continued to repeat the same route.');
            }
            $fixture->container->alias('service', $id === 'left' ? 'right' : 'left');
            return false;
        },
        static fn(): never => throw new \LogicException('No local route was selected.'),
    );
    $fixture->container = new Container($fallback, new ContainerBuilder()->build(), aliases: ['service' => 'left']);
    $operation = $read
        ? static fn(): mixed => $fixture->container->get('service')
        : static fn(): bool => $fixture->container->has('service');

    expect($operation)->toThrow(CircularDependencyException::class, 'service')
        ->and(count($inspected))->toBeLessThanOrEqual(4);
})->with(['has' => false, 'get' => true]);

test('revisiting an alias target can use a newly published value', function (bool $read): void {
    $fixture = new LookupFixture();
    $inspected = [];
    $value = new \stdClass();
    $fallback = new Fallback(
        static function (string $id) use ($fixture, $value, &$inspected): bool {
            $inspected[] = $id;
            if ($id === 'left' && count($inspected) === 3) {
                $fixture->container->set('right', $value);
            }
            $fixture->container->alias('service', $id === 'left' ? 'right' : 'left');
            return false;
        },
        static fn(): never => throw new \LogicException('No fallback should create the entry.'),
    );
    $container = $fixture->container = new Container($fallback, new ContainerBuilder()->build(), aliases: ['service' => 'left']);

    if (!$read) {
        expect($container->has('service'))->toBeTrue();
    }
    expect($container->get('service'))->toBe($value)
        ->and($inspected)->toBe(['left', 'right', 'left']);
})->with(['has' => false, 'get' => true]);
