<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalLookupMutation;

use Componenta\DI\Container;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final class RetargetingContainer implements ContainerInterface
{
    public int $getCalls = 0;
    private bool $armed = true;

    public function __construct(
        private readonly Container $container,
        private readonly string $alias,
        private readonly string $target,
        private readonly bool $owns,
    ) {}

    public function has(string $id): bool
    {
        if ($id !== 'requested') {
            return false;
        }
        if ($this->armed) {
            $this->armed = false;
            $this->container->alias($this->alias, $this->target);
        }

        return $this->owns;
    }

    public function get(string $id): mixed
    {
        ++$this->getCalls;
        return new Product('external');
    }
}

test('local resolution uses current alias routing after an external ownership query', function (bool $ancestor): void {
    $old = new Product('old');
    $current = new Product('current');
    $container = (new ContainerBuilder())->addServices(['old' => $old, 'current' => $current])->build();
    $container->alias('parent', 'old');
    $container->alias('requested', 'parent');
    $container->alias('sibling', 'old');
    expect($container->get('requested'))->toBe($old)
        ->and($container->get('sibling'))->toBe($old);
    $external = new RetargetingContainer($container, $ancestor ? 'parent' : 'requested', 'current', false);
    $container->addContainer($external);

    expect($container->get('requested'))->toBe($current)
        ->and($container->get('requested'))->toBe($current)
        ->and($container->get('old'))->toBe($old)
        ->and($container->get('sibling'))->toBe($old)
        ->and($container->get('current'))->toBe($current)
        ->and($external->getCalls)->toBe(0);
})->with(['requested alias' => false, 'ancestor alias' => true]);

test('external ownership cannot override an alias retargeted to protected core during its query', function (bool $ancestor): void {
    $old = new Product('old');
    $container = (new ContainerBuilder())->addService('old', $old)->build();
    $container->alias('parent', 'old');
    $container->alias('requested', 'parent');
    expect($container->get('requested'))->toBe($old);
    $external = new RetargetingContainer($container, $ancestor ? 'parent' : 'requested', ContainerInterface::class, true);
    $container->addContainer($external);

    expect($container->get('requested'))->toBe($container)
        ->and($container->get('requested'))->toBe($container)
        ->and($container->get(ContainerInterface::class))->toBe($container)
        ->and($container->get('old'))->toBe($old)
        ->and($external->getCalls)->toBe(0);
})->with(['requested alias' => false, 'ancestor alias' => true]);
