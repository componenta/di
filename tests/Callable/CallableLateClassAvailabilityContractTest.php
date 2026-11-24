<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableLateClassAvailabilityContract;

use Closure;
use Componenta\DI\CallableResolver;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

final readonly class Receiver
{
    public function __construct(private string $label) {}

    public function instance(string $value): string
    {
        return $this->label . ':' . $value;
    }

    public static function native(string $value): string
    {
        return 'native:' . $value;
    }

    public function create(): self
    {
        return new self('factory:' . $this->label);
    }
}

interface StaticDispatch
{
    /** @param array<array-key, mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): mixed;
}

abstract class AbstractReceiver implements StaticDispatch
{
    public static function native(string $value): string
    {
        return 'native:' . $value;
    }

    /** @param array<array-key, mixed> $arguments */
    abstract public static function __callStatic(string $name, array $arguments): mixed;
}

final class EmptyServices implements ContainerInterface
{
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): mixed
    {
        throw new LogicException('No service was advertised: ' . $id);
    }
}

/** @return array{class-string<Receiver>,Closure(string):void} */
function receiverAlias(int $loadOn): array
{
    /** @var class-string<Receiver> $alias */
    $alias = __NAMESPACE__ . '\\LateReceiver_' . bin2hex(random_bytes(6));
    if ($loadOn === 0) {
        class_alias(Receiver::class, $alias, autoload: false);
    }

    $attempts = 0;
    $loader = static function (string $name) use ($alias, $loadOn, &$attempts): void {
        if ($name === $alias && ++$attempts === $loadOn) {
            class_alias(Receiver::class, $alias, autoload: false);
        }
    };
    return [$alias, $loader];
}

test('method strings keep the same receiver selection when the class is loaded during resolution', function (
    string $method,
    int $loadOn,
    string $label,
    int $expectedBuilds,
): void {
    [$alias, $loader] = receiverAlias($loadOn);
    $builds = 0;
    $di = container();
    $di->set($alias, Definition::factory(static function () use (&$builds): Receiver {
        ++$builds;
        return new Receiver('service');
    }));

    spl_autoload_register($loader);
    try {
        $first = $di->call($alias . '::' . $method, ['value' => 'first']);
        $second = $di->call($alias . '::' . $method, ['value' => 'second']);
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($first)->toBe($label . ':first')
        ->and($second)->toBe($label . ':second')
        ->and($builds)->toBe($expectedBuilds);
})->with([
    'loaded instance control' => ['instance', 0, 'service', 1],
    'instance declared during fallback probe' => ['instance', 3, 'service', 1],
    'loaded static control' => ['native', 0, 'native', 0],
    'static declared during class probe' => ['native', 2, 'native', 0],
    'static declared during fallback probe' => ['native', 3, 'native', 0],
]);

test('method discovery observes the fallback without retrying the autoloader', function (): void {
    [$alias, $loader] = receiverAlias(4);
    $di = container();
    $di->set($alias, new Receiver('service'));

    spl_autoload_register($loader);
    try {
        expect(fn() => $di->resolve($alias . '::instance'))
            ->toThrow(InvalidCallableException::class, 'Cannot convert value of type "string" to a callable.')
            ->and(class_exists($alias, false))->toBeFalse();
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('an unbound method array can resolve a static method declared during class discovery', function (int $loadOn): void {
    [$alias, $loader] = receiverAlias($loadOn);
    $resolver = new CallableResolver(new EmptyServices());

    spl_autoload_register($loader);
    try {
        $callable = $resolver->resolve([$alias, 'native']);
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($callable('value'))->toBe('native:value');
})->with(['loaded control' => 0, 'class probe' => 2, 'fallback probe' => 3]);

test('a method array uses a static method revealed by service lookup without constructing its owner', function (int $loadOn): void {
    [$alias, $loader] = receiverAlias($loadOn);
    $di = container();

    spl_autoload_register($loader);
    try {
        expect($di->call([$alias, 'native'], ['value' => 'test']))->toBe('native:test');
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['loaded control' => 0, 'service lookup loads the class' => 2]);

test('a method array can still use a registered receiver whose class name is unavailable', function (): void {
    [$alias, $loader] = receiverAlias(2);
    $di = container();
    $di->set($alias, new class () {
        public function native(string $value): string
        {
            return 'registered:' . $value;
        }
    });

    spl_autoload_register($loader);
    try {
        expect($di->call([$alias, 'native'], ['value' => 'test']))->toBe('registered:test')
            ->and(class_exists($alias, false))->toBeFalse();
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('late abstract static dispatch does not hide a registered method array receiver', function (
    string $type,
    string $method,
    string $label,
    int $expectedGets,
): void {
    $owner = __NAMESPACE__ . '\\LateAbstractOwner_' . bin2hex(random_bytes(6));
    $services = new class ($owner, $type) implements ContainerInterface {
        public int $gets = 0;

        public function __construct(private readonly string $owner, private readonly string $type) {}

        public function has(string $id): bool
        {
            if ($id !== $this->owner) {
                return false;
            }
            if (!class_exists($id, false) && !interface_exists($id, false)) {
                class_alias($this->type, $id, autoload: false);
            }
            return true;
        }

        public function get(string $id): Receiver
        {
            if ($id !== $this->owner) {
                throw new LogicException('No service was advertised: ' . $id);
            }
            ++$this->gets;
            return new Receiver('registered');
        }
    };

    $resolver = new CallableResolver($services);
    $first = $resolver->resolve([$owner, $method]);
    $second = $resolver->resolve([$owner, $method]);

    expect($first('test'))->toBe($label . ':test')
        ->and($second('retry'))->toBe($label . ':retry')
        ->and($services->gets)->toBe(2 * $expectedGets);
})->with([
    'interface dispatcher' => [StaticDispatch::class, 'instance', 'registered', 1],
    'abstract class dispatcher' => [AbstractReceiver::class, 'instance', 'registered', 1],
    'concrete static method on abstract class' => [AbstractReceiver::class, 'native', 'native', 0],
]);

test('loaded abstract dispatch allows registered instance methods through the usual container entry points', function (
    string $owner,
    string $route,
): void {
    $di = container();
    $di->set($owner, new Receiver('first'));
    $specification = $route === 'string' ? $owner . '::instance' : [$owner, 'instance'];
    if ($route === 'delegator') {
        $di->set('product', 'value');
        $di->delegator('product', $specification);
        expect($di->get('product'))->toBe('first:value');

        $di->set($owner, new Receiver('current'));
        expect($di->get('product'))->toBe('current:value');
        return;
    }

    expect($di->call($specification, ['value' => 'value']))->toBe('first:value');
})->with([
    'interface dispatcher' => [StaticDispatch::class],
    'abstract class dispatcher' => [AbstractReceiver::class],
])->with(['array', 'string', 'delegator']);

test('an application autoloader retains its own abstract dispatch error from a nested native probe', function (): void {
    $alias = __NAMESPACE__ . '\\FailingAbstractOwner_' . bin2hex(random_bytes(6));
    $error = null;
    $di = container();
    $di->set($alias, new Receiver('registered'));
    $loader = static function (string $name) use ($alias, &$error): void {
        if ($name === $alias) {
            class_alias(AbstractReceiver::class, $alias, autoload: false);
            try {
                is_callable([$name, 'instance']);
            } catch (\Error $failure) {
                $error = $failure;
                throw $failure;
            }
        }
    };
    spl_autoload_register($loader);
    try {
        try {
            $di->resolve([$alias, 'instance']);
            throw new LogicException('The autoload failure must escape the native probe.');
        } catch (InvalidCallableException $failure) {
            expect($error)->toBeInstanceOf(\Error::class)
                ->and($failure->getPrevious())->toBe($error);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('configured factory methods can use an owner whose type has abstract static dispatch', function (string $owner): void {
    $di = (new ContainerBuilder())
        ->addService($owner, new Receiver('registered'))
        ->addFactory('product', [$owner, 'create'])
        ->build();

    $expected = new Receiver('factory:registered');
    expect($di->get('product'))->toEqual($expected)
        ->and($di->make('product'))->toEqual($expected);
})->with([
    'interface dispatcher' => [StaticDispatch::class],
    'abstract class dispatcher' => [AbstractReceiver::class],
]);

test('abstract static dispatch does not hide an exact string binding for a delegator', function (string $owner): void {
    $id = $owner . '::decorate';
    $di = container();
    $original = static fn(string $entry): string => $entry . ':original';
    $di->set('product', 'base');
    $di->set($id, $original);

    expect($di->resolve($id))->toBe($original);
    $di->delegator('product', $id);
    expect($di->get('product'))->toBe('base:original');

    $di->set($id, static fn(string $entry): string => $entry . ':current');

    expect($di->get('product'))->toBe('base:current');
})->with([
    'interface dispatcher' => [StaticDispatch::class],
    'abstract class dispatcher' => [AbstractReceiver::class],
]);

test('a late class without a binding retains the missing-service diagnostic', function (bool $method, int $loadOn): void {
    [$alias, $loader] = receiverAlias($loadOn);
    $resolver = new CallableResolver(new EmptyServices());
    $source = $method ? [$alias, 'instance'] : $alias;

    spl_autoload_register($loader);
    try {
        expect(fn() => $resolver->resolve($source))
            ->toThrow(InvalidCallableException::class, 'Service "' . $alias . '" is not defined in the container.');
    } finally {
        spl_autoload_unregister($loader);
    }
})->with([
    'loaded class name control' => [false, 0],
    'class name fallback probe' => [false, 2],
    'loaded method array control' => [true, 0],
    'method array fallback probe' => [true, 3],
]);
