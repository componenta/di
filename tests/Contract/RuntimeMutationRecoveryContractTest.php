<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RuntimeMutationRecovery;

use Componenta\DI\Container;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Fiber;
use LogicException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

final class ExternalEntries implements ContainerInterface
{
    /** @param array<string, string> $entries */
    public function __construct(private readonly array $entries) {}

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): string
    {
        return $this->entries[$id] ?? throw new LogicException('Unexpected external lookup.');
    }
}

test('replacing a warmed value with a definition invalidates canonical and alias results', function (): void {
    $container = container();
    $container->alias('alias', 'entry');
    $container->set('entry', 'original');
    expect($container->get('entry'))->toBe('original')
        ->and($container->get('alias'))->toBe('original');

    $calls = 0;
    $container->set('alias', Definition::factory(static function () use (&$calls): object {
        ++$calls;
        return new \stdClass();
    }));

    $result = $container->get('entry');
    expect($result)->toBeInstanceOf(\stdClass::class)
        ->and($container->get('alias'))->toBe($result)
        ->and($container->get('entry'))->toBe($result)
        ->and($calls)->toBe(1);
});

test('adding external containers preserves earlier providers and first-owner precedence', function (): void {
    $container = container();
    $first = new ExternalEntries(['first' => 'one', 'shared' => 'earlier']);
    $second = new ExternalEntries(['second' => 'two', 'shared' => 'later']);
    $container->addContainer($first);
    $container->addContainer($second);
    $container->addContainer($first);

    expect($container->get('first'))->toBe('one')
        ->and($container->get('second'))->toBe('two')
        ->and($container->get('shared'))->toBe('earlier')
        ->and($container->has('first'))->toBeTrue()
        ->and($container->has('second'))->toBeTrue();
});

test('a service cycle inside a Fiber reports the complete chain and permits recovery', function (bool $fresh): void {
    $container = container();
    $attempts = 0;
    $read = static fn(): mixed => $fresh ? $container->make('first') : $container->get('first');
    $container->set('first', Definition::factory(static function () use ($container, $fresh, &$attempts): mixed {
        if (++$attempts > 3) {
            throw new LogicException('The cycle was not detected.');
        }
        return $fresh ? $container->make('second') : $container->get('second');
    }));
    $container->set('second', Definition::factory(static fn(): mixed => $read()));
    $fiber = new Fiber(static function () use ($read): CircularDependencyException {
        try {
            $read();
        } catch (CircularDependencyException $exception) {
            return $exception;
        }
        throw new LogicException('The cycle unexpectedly resolved.');
    });

    $fiber->start();
    $exception = $fiber->getReturn();
    if (!$exception instanceof CircularDependencyException) {
        throw new LogicException('Expected a circular dependency diagnostic.');
    }
    expect($exception->chain)->toBe(['first', 'second', 'first']);

    $replacement = new \stdClass();
    $container->set('second', Definition::factory(static fn(): object => $replacement));
    $recovery = new Fiber(static fn(): mixed => $read());
    $recovery->start();
    expect($recovery->getReturn())->toBe($replacement);
})->with(['shared' => false, 'fresh' => true]);

test('external recursion is detected and the lookup guard is cleared after failure', function (): void {
    $container = container();
    $external = new class ($container) implements ContainerInterface {
        public bool $forward = true;
        private int $depth = 0;

        public function __construct(private readonly Container $parent) {}

        public function has(string $id): bool
        {
            return $id === 'entry';
        }

        public function get(string $id): mixed
        {
            if (!$this->forward) {
                return 'recovered';
            }
            if (++$this->depth > 3) {
                --$this->depth;
                throw new LogicException('External recursion was not detected.');
            }
            try {
                return $this->parent->get($id);
            } finally {
                --$this->depth;
            }
        }
    };
    $container->addContainer($external);

    expect(fn(): mixed => $container->get('entry'))->toThrow(CircularDependencyException::class);
    $external->forward = false;
    expect($container->get('entry'))->toBe('recovered');
});

test('runtime registration rejects empty service ids', function (bool $delegator): void {
    $container = container();

    expect(static function () use ($container, $delegator): void {
        if ($delegator) {
            $container->delegator('', static fn(mixed $entry): mixed => $entry);
        } else {
            $container->set('', 'value');
        }
    })->toThrow(InvalidConfigurationException::class, 'empty DI id');
})->with(['value' => false, 'delegator' => true]);
