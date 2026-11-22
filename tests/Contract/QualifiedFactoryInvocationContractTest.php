<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\QualifiedFactoryInvocation;

use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;
use LogicException;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Throwable;
use WeakReference;

final readonly class Product
{
    /** @param array<string|int, mixed> $parameters */
    public function __construct(public ContainerValue $container, public array $parameters, public string $calledClass) {}
}

class Ancestor
{
    public int $calls = 0;
    public ?Throwable $failure = null;

    /** @param array<string|int, mixed> $parameters */
    public function create(ContainerValue $container, array &$parameters): Product
    {
        ++$this->calls;
        $parameters['factory'] = 'ancestor';
        return new Product($container, $parameters, static::class);
    }

    /** @param array<string|int, mixed> $parameters */
    public static function createStatic(ContainerValue $container, array $parameters): Product
    {
        return new Product($container, $parameters, static::class);
    }

    public function fail(ContainerValue $container): never
    {
        ++$this->calls;
        throw $this->failure ?? new LogicException('Expected the configured failure.');
    }
}

final class Child extends Ancestor
{
    /** @param array<string|int, mixed> $parameters */
    public function create(ContainerValue $container, array &$parameters): Product
    {
        throw new LogicException('The qualified ancestor factory must not dispatch the child override.');
    }
}

final class LifetimeState
{
    public int $released = 0;
    /** @var WeakReference<LifetimeChild>|null */
    public ?WeakReference $receiver = null;
}

class LifetimeAncestor
{
    public static ?LifetimeState $current = null;

    public function __construct(private readonly LifetimeState $state) {}

    public function __destruct()
    {
        ++$this->state->released;
    }

    public static function create(ContainerValue $container): Product
    {
        $state = self::$current ?? throw new LogicException('Expected the current lifetime state.');
        return new Product($container, [
            'receiverAlive' => $state->receiver?->get() !== null,
            'released' => $state->released,
        ], static::class);
    }
}

final class LifetimeChild extends LifetimeAncestor {}

function allowingQualifiedCallable(Closure $operation): mixed
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

function nativeCallable(mixed $callable): callable
{
    if (!is_callable($callable)) {
        throw new LogicException('Expected a native callable for the qualified factory.');
    }
    return $callable;
}

test('qualified factory arrays invoke their selected ancestor with reference parameters', function (string $route): void {
    allowingQualifiedCallable(static function () use ($route): void {
        $owner = new Child();
        $method = Ancestor::class . '::create';
        $callable = nativeCallable([$owner, $method]);
        $builder = (new ContainerBuilder())->addService(Child::class, $owner);
        match ($route) {
            'configured pair' => $builder->addFactory('product', $callable),
            'explicit definition' => $builder->addDefinition('product', new FactoryDefinition($callable)),
            'deferred service pair' => $builder->addFactory('product', [Child::class, $method]),
            'callable service' => $builder->addService('factory.callable', $callable)->addFactory('product', 'factory.callable'),
            default => throw new LogicException('Unexpected factory route.'),
        };
        $container = $builder->build();
        $facade = $container->get(ContainerValue::class);
        Assert::assertInstanceOf(ContainerValue::class, $facade);
        $nativeParameters = ['value' => 'native'];
        $native = call_user_func_array($callable, [$facade, &$nativeParameters]);

        expect($native)->toEqual(new Product($facade, ['value' => 'native', 'factory' => 'ancestor'], Child::class))
            ->and($nativeParameters)->toBe(['value' => 'native', 'factory' => 'ancestor']);
        $owner->calls = 0;
        $shared = $container->get('product');
        $parameters = ['value' => 'fresh'];
        $fresh = $container->make('product', $parameters);

        expect($shared)->toEqual(new Product($facade, ['factory' => 'ancestor'], Child::class))
            ->and($container->get('product'))->toBe($shared)
            ->and($fresh)->toEqual(new Product($facade, ['value' => 'fresh', 'factory' => 'ancestor'], Child::class))
            ->and($fresh)->not->toBe($shared)
            ->and($parameters)->toBe(['value' => 'fresh'])
            ->and($owner->calls)->toBe(2);
    });
})->with(['configured pair', 'explicit definition', 'deferred service pair', 'callable service']);

test('qualified static factory arrays preserve the native called class', function (bool $object): void {
    allowingQualifiedCallable(static function () use ($object): void {
        $callable = nativeCallable([$object ? new Child() : Child::class, Ancestor::class . '::createStatic']);
        $container = (new ContainerBuilder())->addFactory('product', $callable)->build();
        $facade = $container->get(ContainerValue::class);
        Assert::assertInstanceOf(ContainerValue::class, $facade);
        $calledClass = $object ? Child::class : Ancestor::class;
        $expected = new Product($facade, ['value' => 'native'], $calledClass);

        expect(call_user_func_array($callable, [$facade, ['value' => 'native']]))->toEqual($expected)
            ->and($container->make('product', ['value' => 'native']))->toEqual($expected)
            ->and($container->get('product'))->toEqual(new Product($facade, [], $calledClass));
    });
})->with(['class receiver' => false, 'object receiver' => true]);

test('qualified factories preserve owned failures and foreign body causes', function (bool $owned): void {
    allowingQualifiedCallable(static function () use ($owned): void {
        $owner = new Child();
        $failure = $owned ? new ResolutionException('Factory body failed.') : new LogicException('Factory body failed.');
        $owner->failure = $failure;
        $container = (new ContainerBuilder())->addFactory('product', [$owner, Ancestor::class . '::fail'])->build();
        $caught = null;
        try {
            $container->get('product');
        } catch (Throwable $error) {
            $caught = $error;
        }

        expect($caught)->toBeInstanceOf(ResolutionException::class)
            ->and($owned ? $caught : $caught?->getPrevious())->toBe($failure)
            ->and($owner->calls)->toBe(1);
    });
})->with(['foreign cause' => false, 'owned error' => true]);

test('qualified static factories retain a temporary object receiver until invocation completes', function (): void {
    allowingQualifiedCallable(static function (): void {
        $state = new LifetimeState();
        LifetimeAncestor::$current = $state;
        $external = new class ($state) implements ContainerInterface {
            public function __construct(private readonly LifetimeState $state) {}

            public function has(string $id): bool
            {
                return $id === 'factory.lifetime';
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new LogicException('Unexpected external factory lookup.');
                }
                $receiver = new LifetimeChild($this->state);
                $this->state->receiver = WeakReference::create($receiver);
                return [$receiver, LifetimeAncestor::class . '::create'];
            }
        };
        $container = (new ContainerBuilder())->addFactory('product', 'factory.lifetime')->build();
        $container->addContainer($external);
        $facade = $container->get(ContainerValue::class);
        Assert::assertInstanceOf(ContainerValue::class, $facade);

        try {
            $native = nativeCallable($external->get('factory.lifetime'));
            expect(call_user_func_array($native, [$facade, []]))->toEqual(new Product($facade, [
                'receiverAlive' => true,
                'released' => 0,
            ], LifetimeChild::class));
            unset($native);
            expect($state->released)->toBe(1);
            $state->released = 0;

            $product = $container->get('product');

            expect($product)->toEqual(new Product($facade, ['receiverAlive' => true, 'released' => 0], LifetimeChild::class))
                ->and($state->released)->toBe(1)
                ->and($state->receiver?->get())->toBeNull()
                ->and($container->get('product'))->toBe($product)
                ->and($state->released)->toBe(1);
        } finally {
            LifetimeAncestor::$current = null;
        }
    });
});
