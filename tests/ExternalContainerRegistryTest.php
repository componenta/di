<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\ExternalContainerRegistry;
use Psr\Container\ContainerInterface;

final class ExternalContainerForRegistryTest implements ContainerInterface
{
    public int $hasCalls = 0;

    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        ++$this->hasCalls;

        return array_key_exists($id, $this->entries);
    }
}

describe('ExternalContainerRegistry', function () {
    it('returns the first owning container in stable registration order', function () {
        $registry = new ExternalContainerRegistry();
        $first = new ExternalContainerForRegistryTest(['shared' => 'first']);
        $second = new ExternalContainerForRegistryTest(['shared' => 'second']);
        $registry->register($first);
        $registry->register($second);

        expect($registry->findOwning('shared'))->toBe($first)
            ->and($registry->findOwning('missing'))->toBeNull();
    });

    it('deduplicates repeated registration of the same instance', function () {
        $registry = new ExternalContainerRegistry();
        $container = new ExternalContainerForRegistryTest(['id' => 1]);
        $registry->register($container);
        $registry->register($container);

        expect($registry->findOwning('missing'))->toBeNull()
            ->and($container->hasCalls)->toBe(1);
    });
});
