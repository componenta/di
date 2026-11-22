<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableLateClassAvailability;

use Closure;
use Componenta\DI\CallableResolver;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidCallableException;
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
