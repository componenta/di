<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ReentrantAvailability;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Fiber;
use LogicException;
use PHPUnit\Framework\Assert;

#[Attribute(Attribute::TARGET_CLASS)]
final class Marker {}

final readonly class Product
{
    public function __construct(public int $cacheProbe = 42) {}
}

final class Observer implements ParameterResolverInterface
{
    public int $calls = 0;

    public function supports(ParameterTarget $target): bool
    {
        if ($target->name === 'cacheProbe') {
            ++$this->calls;
        }
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        throw new LogicException('This resolver only observes classification.');
    }
}

function build(bool $targeted, Observer $observer): Container
{
    $factory = new ContainerFactory();
    $config = new Config([], new Environment([]));
    $definitions = new DependencyDefinitions([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(Marker::class)],
        ConfigKey::PARAMETER_RESOLVERS => [1500 => $observer],
    ]);
    $value = $targeted
        ? $factory->createWithTargetedAttributeDiscovery($config, $definitions)
        : $factory->create($config, $definitions);
    Assert::assertInstanceOf(Container::class, $value->container);
    return $value->container;
}

test('resuming a concurrent poll of an already observed declaration does not invalidate a warm plan twice', function (bool $targeted): void {
    $suffix = bin2hex(random_bytes(5));
    $marker = 'Marker_' . $suffix;
    $probeClass = 'Probe_' . $suffix;
    eval(sprintf('namespace %s; #[%s] final class %s {}', __NAMESPACE__, $marker, $probeClass));
    $alias = __NAMESPACE__ . '\\' . $marker;
    $probe = __NAMESPACE__ . '\\' . $probeClass;
    $counter = new Observer();
    $di = build($targeted, $counter);
    $another = new Observer();
    $unrelated = build($targeted, $another);

    expect($di->has($probe))->toBeTrue();
    $initial = $di->get(Product::class);
    expect($initial)->toEqual(new Product())->and($counter->calls)->toBe(1);
    expect($unrelated->make(Product::class))->toEqual(new Product())->and($another->calls)->toBe(1);

    $wait = true;
    $loads = 0;
    $loader = static function (string $id) use ($alias, &$wait, &$loads): void {
        if ($id === $alias) {
            ++$loads;
            if ($wait) {
                $wait = false;
                Fiber::suspend('autoload ready to yield');
            }
        }
    };
    spl_autoload_register($loader);

    try {
        $fiber = new Fiber(static fn(): bool => $di->has($probe));
        expect($fiber->start())->toBe('autoload ready to yield');
        class_alias(Marker::class, $alias, false);

        expect($di->make(Product::class))->toEqual(new Product())
            ->and($counter->calls)->toBe(2);
        $fiber->resume();

        expect($fiber->getReturn())->toBeTrue()
            ->and($di->get(Product::class))->toBe($initial)
            ->and($di->make(Product::class))->toEqual(new Product())
            ->and($counter->calls)->toBe(2)
            ->and($loads)->toBe(1)
            ->and($unrelated->make(Product::class))->toEqual(new Product())
            ->and($another->calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['global discovery' => false, 'targeted discovery' => true]);
