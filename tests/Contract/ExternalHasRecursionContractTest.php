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
