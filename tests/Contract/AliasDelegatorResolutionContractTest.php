<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Componenta\DI\Exception\DelegatorException;
use Fiber;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

use function Componenta\DI\Tests\Support\container;

final class AliasDelegatorState
{
    public bool $reenter = true;
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
            return $state->reenter ? $container->get($crossAlias ? 'first' : 'second') : $entry . ':done';
        },
    );

    expect(fn() => $container->get('first'))->toThrow(CircularDependencyException::class);

    $state->reenter = false;

    expect($container->get('first'))->toBe('base:done');
})->with(['self cycle' => false, 'cross alias cycle' => true]);

test('aliases cannot reenter a shared source factory before it returns', function (): void {
    $container = container([
        ConfigKey::FACTORIES => [
            'source' => static fn(ContainerValue $container): mixed => $container->get('second'),
        ],
        ConfigKey::ALIASES => ['first' => 'source', 'second' => 'source'],
    ]);

    expect(fn() => $container->get('first'))->toThrow(CircularDependencyException::class);
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
