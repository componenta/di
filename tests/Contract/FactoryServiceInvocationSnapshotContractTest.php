<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryServiceInvocationSnapshot;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $route) {}
}

final class OriginalFactory
{
    public static function first(): Product
    {
        return new Product('original:first');
    }

    public static function alternate(): Product
    {
        return new Product('original:alternate');
    }
}

final class ReplacementFactory
{
    public static function first(): Product
    {
        return new Product('replacement:first');
    }

    public static function alternate(): Product
    {
        return new Product('replacement:alternate');
    }
}

final readonly class FactoryServiceContainer implements ContainerInterface
{
    /** @param array<array-key, mixed> $factory */
    public function __construct(private array $factory) {}

    public function has(string $id): bool
    {
        return $id === 'factory.service';
    }

    public function get(string $id): mixed
    {
        return $this->has($id) ? $this->factory : throw new LogicException('Unexpected external lookup.');
    }
}

test('factory service resolution captures callable routing before validation can mutate referenced slots', function (
    string $mutation,
    ?string $nextRoute,
    bool $external,
    bool $shared,
): void {
    $requested = __NAMESPACE__ . '\\AutoloadedFactory_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'first';
    $specification = [&$owner, &$method];
    $builder = (new ContainerBuilder())->addFactory('product', 'factory.service');
    if (!$external) {
        $builder->addService('factory.service', $specification);
    }
    $container = $builder->build();
    if ($external) {
        $container->addContainer(new FactoryServiceContainer($specification));
    }
    $loads = 0;
    $autoload = static function (string $class) use ($requested, $mutation, &$owner, &$method, &$loads): void {
        if ($class !== $requested) {
            return;
        }
        ++$loads;
        class_alias(OriginalFactory::class, $class);
        if ($mutation === 'owner' || $mutation === 'both') {
            $owner = ReplacementFactory::class;
        } elseif ($mutation === 'invalid owner') {
            $owner = '';
        }
        if ($mutation === 'method' || $mutation === 'both') {
            $method = 'alternate';
        }
    };
    spl_autoload_register($autoload);

    try {
        $first = $shared ? $container->get('product') : $container->make('product');
        expect($first)->toEqual(new Product('original:first'))
            ->and($loads)->toBe(1);
        if ($shared) {
            expect($container->get('product'))->toBe($first);
        }

        if ($nextRoute === null) {
            expect(fn() => $container->make('product'))->toThrow(InvalidConfigurationException::class);
        } else {
            expect($container->make('product'))->toEqual(new Product($nextRoute));
        }
        expect($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with([
    'receiver changed' => ['owner', 'replacement:first'],
    'method changed' => ['method', 'original:alternate'],
    'both changed' => ['both', 'replacement:alternate'],
    'receiver invalidated' => ['invalid owner', null],
])->with(['local factory service' => false, 'external factory service' => true])
    ->with(['make' => false, 'get' => true]);
