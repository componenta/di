<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Delegator\DelegatorCallableBoundaryContract;

use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerConstruction;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;

final readonly class Product
{
    public function __construct(public string $value, public ?object $owner = null) {}
}

final class RetryDecorator
{
    public int $attempts = 0;

    public function __construct(private readonly Throwable $failure) {}

    public function __invoke(Product $entry): Product
    {
        if (++$this->attempts === 1) {
            throw $this->failure;
        }
        return new Product($entry->value . ':recovered', $this);
    }
}

final class FreshDecoratorProvider implements ContainerInterface
{
    public bool $active = true;
    public int $creations = 0;

    public function __construct(private readonly Throwable $failure) {}

    public function has(string $id): bool
    {
        return $this->active && $id === 'retry.decorator';
    }

    public function get(string $id): RetryDecorator
    {
        if (!$this->has($id)) {
            throw new LogicException('Unexpected external lookup.');
        }
        ++$this->creations;
        return new RetryDecorator($this->failure);
    }
}

test('a failed delegator invocation can recover with its resolved owner and later observe replacement', function (bool $componenta): void {
    $failure = $componenta
        ? new InvalidConfigurationException('Retry the same decorator.')
        : new LogicException('Retry the same decorator.');
    $provider = new FreshDecoratorProvider($failure);
    $base = new Product('base');
    $container = (new ContainerBuilder())->addService('product', $base)->build();
    $container->addContainer($provider);
    $container->delegator('product', 'retry.decorator');
    $caught = null;

    try {
        $container->get('product');
    } catch (Throwable $error) {
        $caught = $error;
    }

    if ($componenta) {
        expect($caught)->toBe($failure);
    } else {
        expect($caught)->toBeInstanceOf(DelegatorException::class)
            ->and($caught?->getPrevious())->toBe($failure);
    }
    $recovered = $container->get('product');
    if (!$recovered instanceof Product || !$recovered->owner instanceof RetryDecorator) {
        throw new LogicException('Expected the recovered decorator result.');
    }
    expect($recovered->value)->toBe('base:recovered')
        ->and($recovered->owner->attempts)->toBe(2)
        ->and($provider->creations)->toBe(1)
        ->and($container->get('product'))->toBe($recovered);

    $provider->active = false;
    $container->set('retry.decorator', static fn(Product $entry): Product => new Product($entry->value . ':replacement'));
    $replacement = $container->get('product');

    expect($replacement)->toEqual(new Product('base:replacement'))
        ->and($replacement)->not->toBe($recovered)
        ->and($container->get('product'))->toBe($replacement);
})->with(['foreign exception' => false, 'Componenta exception' => true]);

test('opaque callable ids keep their complete spelling through delegation and replacement', function (string $id): void {
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $original = static fn(Product $entry): Product => new Product($entry->value . ':original');
    $container->set($id, $original);
    $container->delegator('product', $id);
    $before = $container->get('product');

    expect($container->resolve($id))->toBe($original)
        ->and($before)->toEqual(new Product('base:original'));

    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $container->set($id, $replacement);
    $after = $container->get('product');

    expect($container->resolve($id))->toBe($replacement)
        ->and($after)->toEqual(new Product('base:replacement'))
        ->and($after)->not->toBe($before)
        ->and($container->get('product'))->toBe($after);

    $container->set($id, new \stdClass());
    expect(fn() => $container->get('product'))->toThrow(InvalidCallableException::class, 'is not invokable');
})->with(['::format', 'policy::', NativeDecorator::class . '::decorateStatic::suffix']);

final class NativeDecorator
{
    public function __invoke(Product $entry): Product
    {
        return new Product($entry->value . ':object');
    }

    public function decorate(Product $entry): Product
    {
        return new Product($entry->value . ':method');
    }

    public static function decorateStatic(Product $entry): Product
    {
        return new Product($entry->value . ':static');
    }
}

test('a supplied delegator resolver handles deferred ids while native callables retain their execution', function (): void {
    $entries = new class () implements EntryResolverInterface {
        public function can(string $id): bool
        {
            return false;
        }

        public function resolve(string $id, EntryParameters $params): mixed
        {
            throw new NotFoundException($id);
        }
    };
    $resolver = new class () implements CallableResolverInterface {
        public function resolve(mixed $callable): callable
        {
            if ($callable !== 'application.decorator') {
                throw new LogicException('Only the configured service id belongs to this resolver.');
            }
            return static fn(Product $entry): Product => new Product($entry->value . ':deferred');
        }
    };
    $container = ContainerConstruction::create(
        $entries,
        new AliasResolver(),
        (new ContainerBuilder())->build(),
        delegators: new DelegatorRegistry($resolver),
    );
    $container->set('product', new Product('base'));
    $native = new NativeDecorator();
    $container->delegator('product', static fn(Product $entry): Product => new Product($entry->value . ':closure'));
    $container->delegator('product', $native);
    $container->delegator('product', [$native, 'decorate']);
    $container->delegator('product', [NativeDecorator::class, 'decorateStatic']);
    $container->delegator('product', 'application.decorator');
    $result = $container->get('product');

    expect($result)->toEqual(new Product('base:closure:object:method:static:deferred'))
        ->and($container->get('product'))->toBe($result);
});
