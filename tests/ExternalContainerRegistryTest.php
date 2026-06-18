<?php

declare(strict_types=1);

use Componenta\DI\ExternalContainerRegistry;
use Psr\Container\ContainerInterface;

function fakeContainer(array $owned): ContainerInterface
{
    return new class ($owned) implements ContainerInterface {
        public function __construct(private array $owned) {}

        public function get(string $id): mixed
        {
            return $this->owned[$id] ?? throw new RuntimeException("no $id");
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->owned);
        }
    };
}

describe('ExternalContainerRegistry', function () {
    it('reports no owner when empty', function () {
        $registry = new ExternalContainerRegistry();

        expect($registry->findOwning('anything'))->toBeNull()
            ->and($registry->has('anything'))->toBeFalse();
    });

    it('finds the container that owns the id', function () {
        $registry = new ExternalContainerRegistry();
        $a = fakeContainer(['alpha' => 1]);
        $b = fakeContainer(['beta' => 2]);
        $registry->register($a);
        $registry->register($b);

        expect($registry->findOwning('beta'))->toBe($b)
            ->and($registry->has('beta'))->toBeTrue();
    });

    it('returns the first registered container when several report ownership', function () {
        $registry = new ExternalContainerRegistry();
        $first = fakeContainer(['shared' => 1]);
        $second = fakeContainer(['shared' => 2]);
        $registry->register($first);
        $registry->register($second);

        expect($registry->findOwning('shared'))->toBe($first);
    });

    it('deduplicates the same container instance on repeated registration', function () {
        $registry = new ExternalContainerRegistry();
        $container = fakeContainer([]);

        $registry->register($container);
        $registry->register($container);

        expect(iterator_to_array($registry, preserve_keys: false))->toBe([$container]);
    });

    it('preserves insertion order on iteration', function () {
        $registry = new ExternalContainerRegistry();
        $a = fakeContainer([]);
        $b = fakeContainer([]);
        $c = fakeContainer([]);
        $registry->register($a);
        $registry->register($b);
        $registry->register($c);

        expect(iterator_to_array($registry, preserve_keys: false))->toBe([$a, $b, $c]);
    });

    it('returns null from findOwning when no registered container owns the id', function () {
        $registry = new ExternalContainerRegistry();
        $registry->register(fakeContainer(['alpha' => 1]));

        expect($registry->findOwning('unknown'))->toBeNull()
            ->and($registry->has('unknown'))->toBeFalse();
    });
});
