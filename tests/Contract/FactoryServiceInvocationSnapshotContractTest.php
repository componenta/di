<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryServiceInvocationSnapshot;

use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $route, public ?object $receiver = null) {}
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

final class MutableFactory
{
    public function __construct(public string $label) {}

    public function first(): Product
    {
        return new Product($this->label . ':first', $this);
    }

    public function alternate(): Product
    {
        return new Product($this->label . ':alternate', $this);
    }
}

test('a selected factory service retains its routing and object identity while loading the invocation context', function (): void {
    $original = new MutableFactory('original');
    $replacement = new MutableFactory('replacement');
    $owner = $original;
    $method = 'first';
    $context = (new ContainerBuilder())->build()->get(ContainerValue::class);
    if (!$context instanceof ContainerValue) {
        throw new LogicException('Expected the factory invocation context.');
    }
    $container = new class ([&$owner, &$method], static function () use ($context, $original, $replacement, &$owner, &$method): ContainerValue {
        $original->label = 'updated business state';
        $owner = $replacement;
        $method = 'alternate';
        return $context;
    }) implements ContainerInterface {
        /**
         * @param array{object, string} $factory
         * @param Closure():ContainerValue $context
         */
        public function __construct(private readonly array $factory, private readonly Closure $context) {}

        public function has(string $id): bool
        {
            return $id === OriginalFactory::class . '::first' || $id === ContainerValue::class;
        }

        public function get(string $id): mixed
        {
            return match ($id) {
                OriginalFactory::class . '::first' => $this->factory,
                ContainerValue::class => ($this->context)(),
                default => throw new LogicException('Unexpected factory runtime lookup.'),
            };
        }
    };
    $resolver = new FactoryResolver($container, new ProxyFactory());
    $prepared = $resolver->validateDefinition('product', new FactoryDefinition(OriginalFactory::class . '::first'));

    $first = $resolver->resolveDefinition('product', $prepared);
    if (!$first instanceof Product) {
        throw new LogicException('Expected the selected factory product.');
    }

    expect($first->route)->toBe('updated business state:first')
        ->and($first->receiver)->toBe($original)
        ->and($owner)->toBe($replacement)
        ->and($method)->toBe('alternate')
        ->and($resolver->resolveDefinition('product', $prepared))->toEqual(new Product('replacement:alternate', $replacement));
});
