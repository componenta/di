<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Delegator\AliasDelegatorResolutionContract;

use Componenta\Config\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Componenta\DI\Exception\DelegatorException;
use Fiber;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Throwable;

use function Componenta\DI\Tests\Support\container;

final class AliasDelegatorState
{
    public bool $reenter = true;
    public int $attempts = 0;
}

test('alias delegators can read the shared source regardless of previous lookups', function (
    string $reference,
    bool $warm,
): void {
    $source = new stdClass();
    $creations = 0;
    $container = container([
        ConfigKey::FACTORIES => [
            'source' => static function () use ($source, &$creations): object {
                ++$creations;
                return $source;
            },
        ],
        ConfigKey::ALIASES => ['decorated' => 'source', 'sibling' => 'source'],
        ConfigKey::DELEGATORS => [
            'decorated' => [
                static fn(object $entry, ContainerInterface $container): array => [
                    $entry,
                    $container->get($reference),
                ],
            ],
        ],
    ]);

    if ($warm) {
        $container->get($reference);
    }

    expect($container->get('decorated'))->toBe([$source, $source])
        ->and($container->get('decorated'))->toBe([$source, $source])
        ->and($container->get('source'))->toBe($source)
        ->and($creations)->toBe(1);
})->with(['canonical source' => 'source', 'sibling alias' => 'sibling'])
    ->with(['cold' => false, 'warm' => true]);

test('alias decoration still rejects real cycles and releases the guard after failure', function (
    bool $crossAlias,
): void {
    $container = container([
        ConfigKey::SERVICES => ['source' => 'base'],
        ConfigKey::ALIASES => ['first' => 'source', 'second' => 'source'],
    ]);
    $state = new AliasDelegatorState();
    $container->delegator(
        'first',
        static fn(string $entry, ContainerInterface $container): mixed => $container->get('second'),
    );
    $container->delegator(
        'second',
        static function (string $entry, ContainerInterface $container) use ($state, $crossAlias): mixed {
            if (!$state->reenter) {
                return $entry . ':done';
            }
            if (++$state->attempts > 1) {
                throw new \LogicException('The cyclic delegator was invoked recursively.');
            }
            return $container->get($crossAlias ? 'first' : 'second');
        },
    );

    expect(fn() => $container->get('first'))->toThrow(CircularDependencyException::class);

    $state->reenter = false;

    expect($container->get('first'))->toBe('base:done');
})->with(['self cycle' => false, 'cross alias cycle' => true]);

test('aliases cannot reenter a shared source factory before it returns', function (): void {
    $source = new stdClass();
    $state = new AliasDelegatorState();
    $container = container([
        ConfigKey::FACTORIES => [
            'source' => static function (ContainerValue $container) use ($source, $state): mixed {
                ++$state->attempts;
                if (!$state->reenter) {
                    return $source;
                }
                if ($state->attempts > 1) {
                    throw new \LogicException('The cyclic source factory was invoked recursively.');
                }
                return $container->get('second');
            },
        ],
        ConfigKey::ALIASES => ['first' => 'source', 'second' => 'source'],
    ]);

    expect(fn() => $container->get('first'))->toThrow(CircularDependencyException::class)
        ->and($state->attempts)->toBe(1);

    $state->reenter = false;
    expect($container->get('first'))->toBe($source)
        ->and($container->get('second'))->toBe($source)
        ->and($state->attempts)->toBe(2);
});

test('a suspended source factory cannot run twice through different aliases', function (): void {
    $creations = 0;
    $source = new stdClass();
    $container = container([
        ConfigKey::FACTORIES => [
            'source' => static function () use (&$creations, $source): object {
                ++$creations;
                Fiber::suspend('creating');
                return $source;
            },
        ],
        ConfigKey::ALIASES => ['first' => 'source', 'second' => 'source'],
    ]);
    $first = new Fiber(static fn(): mixed => $container->get('first'));

    expect($first->start())->toBe('creating')
        ->and(fn() => $container->get('second'))->toThrow(ConcurrentResolutionException::class);

    $first->resume();

    expect($first->getReturn())->toBe($source)
        ->and($container->get('second'))->toBe($source)
        ->and($creations)->toBe(1);
});

test('a suspended alias delegator protects its result while allowing access to the ready source', function (): void {
    $decorations = 0;
    $container = container([
        ConfigKey::SERVICES => ['source' => 'base'],
        ConfigKey::ALIASES => ['decorated' => 'source', 'sibling' => 'source'],
        ConfigKey::DELEGATORS => [
            'decorated' => [
                static function (string $entry) use (&$decorations): string {
                    ++$decorations;
                    Fiber::suspend('decorating');
                    return $entry . ':done';
                },
            ],
        ],
    ]);
    $first = new Fiber(static fn(): mixed => $container->get('decorated'));

    expect($first->start())->toBe('decorating')
        ->and(fn() => $container->get('decorated'))->toThrow(ConcurrentResolutionException::class)
        ->and($container->get('source'))->toBe('base')
        ->and($container->get('sibling'))->toBe('base');

    $first->resume();

    expect($first->getReturn())->toBe('base:done')
        ->and($container->get('decorated'))->toBe('base:done')
        ->and($decorations)->toBe(1);
});

test('failed alias decoration can retry without rebuilding its shared source', function (): void {
    $creations = 0;
    $attempts = 0;
    $container = container([
        ConfigKey::FACTORIES => [
            'source' => static function () use (&$creations): string {
                ++$creations;
                return 'base';
            },
        ],
        ConfigKey::ALIASES => ['decorated' => 'source'],
        ConfigKey::DELEGATORS => [
            'decorated' => [
                static function (string $entry) use (&$attempts): string {
                    if (++$attempts === 1) {
                        throw new RuntimeException('try again');
                    }
                    return $entry . ':done';
                },
            ],
        ],
    ]);

    expect(fn() => $container->get('decorated'))->toThrow(DelegatorException::class)
        ->and($container->get('decorated'))->toBe('base:done')
        ->and($creations)->toBe(1)
        ->and($attempts)->toBe(2);
});

test('a local source cycle takes precedence over concurrent shared ownership', function (bool $inFiber): void {
    $container = container();
    $container->alias('owner.alias', 'source');
    $container->alias('cycle.alias', 'source');
    $source = new stdClass();
    $builds = 0;
    $owner = null;
    $suspended = null;
    $container->set('source', Definition::factory(static function () use (
        $container,
        $source,
        &$builds,
        &$owner,
        &$suspended,
    ): mixed {
        if (++$builds === 1) {
            $owner = new Fiber(static fn(): mixed => $container->get('owner.alias'));
            $suspended = $owner->start();
            return $container->get('cycle.alias');
        }
        if ($builds === 2) {
            Fiber::suspend('shared-source-suspended');
            return $source;
        }
        throw new RuntimeException('A shared source was constructed more than once.');
    }));
    $read = static fn(): object => $container->make('source');
    $cycleContext = $inFiber ? new Fiber($read) : null;
    $cycle = null;

    try {
        try {
            $cycleContext === null ? $read() : $cycleContext->start();
        } catch (Throwable $exception) {
            $cycle = $exception;
        }

        expect($cycle)->toBeInstanceOf(CircularDependencyException::class)
            ->and($cycle instanceof CircularDependencyException ? $cycle->chain : null)->toBe(['source', 'source'])
            ->and($suspended)->toBe('shared-source-suspended')
            ->and($builds)->toBe(2)
            ->and(fn() => $container->get('source'))->toThrow(ConcurrentResolutionException::class);

        if (!$owner instanceof Fiber) {
            throw new RuntimeException('The shared source must have an active owner.');
        }
        $owner->resume();

        expect($owner->getReturn())->toBe($source)
            ->and($container->get('owner.alias'))->toBe($source)
            ->and($container->get('cycle.alias'))->toBe($source)
            ->and($container->get('source'))->toBe($source)
            ->and($builds)->toBe(2);
    } finally {
        if ($owner instanceof Fiber && $owner->isSuspended()) {
            $owner->resume();
        }
    }
})->with(['main context' => false, 'Fiber context' => true]);

test('main-context shared construction prevents a nested Fiber from constructing the same source', function (): void {
    $container = container();
    $container->alias('first', 'source');
    $container->alias('second', 'source');
    $source = new stdClass();
    $builds = 0;
    $error = null;
    $container->set('source', Definition::factory(static function () use (
        $container,
        $source,
        &$builds,
        &$error,
    ): object {
        if (++$builds > 1) {
            throw new RuntimeException('A concurrent source construction was allowed.');
        }
        $fiber = new Fiber(static fn(): mixed => $container->get('second'));
        try {
            $fiber->start();
        } catch (ConcurrentResolutionException $exception) {
            $error = $exception;
        }
        return $source;
    }));

    expect($container->get('first'))->toBe($source)
        ->and($error)->toBeInstanceOf(ConcurrentResolutionException::class)
        ->and($container->get('second'))->toBe($source)
        ->and($container->get('source'))->toBe($source)
        ->and($builds)->toBe(1);
});
