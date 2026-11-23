<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalHasRecursion;

use Componenta\DI\Container;
use Componenta\DI\Exception\NotFoundException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

final class RecursiveExternal implements ContainerInterface
{
    public int $queries = 0;
    public bool $recursive = true;
    public bool $independentAvailable = false;

    public function __construct(private readonly Container $container) {}

    public function has(string $id): bool
    {
        if ($id === 'independent') {
            return true;
        }
        if ($id !== 'recursive') {
            return false;
        }
        ++$this->queries;
        if (!$this->recursive) {
            return true;
        }
        // Bound a broken recursion guard so the regression fails without hanging.
        if ($this->queries >= 3) {
            return true;
        }
        $this->independentAvailable = $this->container->has('independent');
        return $this->container->has($id);
    }

    public function get(string $id): string
    {
        return match ($id) {
            'independent' => 'independent value',
            'recursive' => 'recovered value',
            default => throw NotFoundException::forService($id),
        };
    }
}

test('recursive external availability stops without blocking independent ids or later recovery', function (): void {
    $di = container();
    $external = new RecursiveExternal($di);
    $di->addContainer($external);

    expect($di->has('recursive'))->toBeFalse()
        ->and($external->queries)->toBe(1)
        ->and($external->independentAvailable)->toBeTrue()
        ->and($di->has('independent'))->toBeTrue()
        ->and($di->get('independent'))->toBe('independent value');

    $external->recursive = false;

    expect($di->has('recursive'))->toBeTrue()
        ->and($di->get('recursive'))->toBe('recovered value');
});

test('an external ownership probe can see other routes for the same id', function (bool $externalRoute, bool $read): void {
    $di = container();
    $external = new class ($di) implements ContainerInterface {
        private bool $probing = false;
        public int $queries = 0;
        public ?bool $nestedAvailability = null;
        public mixed $nestedValue = null;

        public function __construct(private readonly Container $parent) {}

        public function has(string $id): bool
        {
            if ($id !== 'entry') {
                return false;
            }

            ++$this->queries;
            if ($this->probing) {
                return false;
            }
            $this->probing = true;
            try {
                $this->nestedAvailability = $this->parent->has($id);
                $this->nestedValue = $this->parent->get($id);
            } finally {
                $this->probing = false;
            }
            return false;
        }

        public function get(string $id): never
        {
            throw NotFoundException::forService($id);
        }
    };
    $di->addContainer($external);
    if ($externalRoute) {
        $owner = container();
        $owner->set('entry', 'value');
        $di->addContainer($owner);
    } else {
        $di->set('entry', 'value');
    }

    expect($read ? $di->get('entry') : $di->has('entry'))->toBe($read ? 'value' : true)
        ->and($external->nestedAvailability)->toBeTrue()
        ->and($external->nestedValue)->toBe('value')
        ->and($external->queries)->toBe(1);
})->with(['local entry' => false, 'second provider' => true])
    ->with(['availability query' => false, 'entry read' => true]);

test('recursive external retrieval is rejected before probing ownership again', function (): void {
    $di = container();
    $provider = new class ($di) implements ContainerInterface {
        public int $probes = 0;

        public function __construct(private readonly Container $parent) {}

        public function has(string $id): bool
        {
            ++$this->probes;
            return true;
        }

        public function get(string $id): mixed
        {
            return $this->parent->get($id);
        }
    };
    $di->addContainer($provider);

    expect(fn() => $di->get('entry'))->toThrow(\Componenta\DI\Exception\CircularDependencyException::class)
        ->and($provider->probes)->toBe(1);
});
