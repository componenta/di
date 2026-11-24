<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\LateMakeCompanionContract;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\AttributeCompositionException;
use LogicException;
use ReflectionClass;

use function Componenta\DI\Tests\Support\fixtureInstance;

final class Product
{
    public bool $ready = true;
}

abstract class Owner
{
    public Product $part;
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Note
{
    public function __construct(public string $value) {}
}

final class Trace
{
    public static int $factoryCalls = 0;
    public static int $bodyCalls = 0;
}

test('Make rejects a newly selected Proxy after using the eager factory', function (bool $targeted, string $placement, string $discovery): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'Companion_' . $suffix;
    $aliasName = __NAMESPACE__ . '\\' . $alias;
    $target = 'Subject_' . $suffix;
    $name = __NAMESPACE__ . '\\' . $target;
    $attribute = sprintf('#[\\%s("part"), %s(Product::class)]', Make::class, $alias);
    $declaration = match ($placement) {
        'property' => sprintf('final class %s extends Owner { %s public Product $part; }', $target, $attribute),
        'constructor' => sprintf(
            'final class %s extends Owner { public function __construct(%s Product $part) { ++Trace::$bodyCalls; $this->part = $part; } }',
            $target,
            $attribute,
        ),
        'callable' => sprintf('function %s(%s Product $part): Product { ++Trace::$bodyCalls; return $part; }', $target, $attribute),
        default => throw new LogicException('Expected a Make injection point.'),
    };
    eval('namespace ' . __NAMESPACE__ . ';' . $declaration);

    Trace::$factoryCalls = Trace::$bodyCalls = 0;
    if ($discovery === 'preloaded') {
        class_alias(Proxy::class, $aliasName);
    }
    $dependency = new DependencyDefinitions([
        ConfigKey::FACTORIES => [
            'part' => static function () use ($aliasName, $discovery): Product {
                ++Trace::$factoryCalls;
                if (!class_exists($aliasName, false)) {
                    class_alias($discovery === 'late proxy' ? Proxy::class : Note::class, $aliasName);
                }
                return new Product();
            },
        ],
    ]);
    $factory = new ContainerFactory();
    $config = new Config([], new Environment([]));
    $di = fixtureInstance(($targeted
        ? $factory->createWithTargetedAttributeDiscovery($config, $dependency)
        : $factory->create($config, $dependency))->container, Container::class);
    $read = static fn(): Product => $placement === 'callable'
        ? fixtureInstance($di->call($name), Product::class)
        : fixtureInstance($di->get($name), Owner::class)->part;

    if ($discovery === 'late proxy') {
        expect($read)->toThrow(AttributeCompositionException::class, 'companion')
            ->and(Trace::$factoryCalls)->toBe(1)
            ->and(Trace::$bodyCalls)->toBe(0);
    }

    $product = $read();
    $proxy = $discovery !== 'unrelated';
    expect(new ReflectionClass(Product::class)->isUninitializedLazyObject($product))->toBe($proxy)
        ->and(Trace::$factoryCalls)->toBe($discovery === 'preloaded' ? 0 : 1)
        ->and(Trace::$bodyCalls)->toBe($placement === 'property' ? 0 : 1)
        ->and($product->ready)->toBeTrue();
    expect(Trace::$factoryCalls)->toBe($discovery === 'late proxy' ? 2 : 1);
})->with(['compatibility' => false, 'targeted' => true])
    ->with(['property', 'constructor', 'callable'])
    ->with(['preloaded', 'late proxy', 'unrelated']);
