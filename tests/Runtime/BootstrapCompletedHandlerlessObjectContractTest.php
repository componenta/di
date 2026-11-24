<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\BootstrapCompletedHandlerlessObjectContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

#[Attribute(Attribute::TARGET_CLASS)]
final class Extension {}

abstract class Product
{
    public static int $constructions = 0;
    public bool $constructed = false;

    public function __construct()
    {
        ++self::$constructions;
        $this->constructed = true;
    }
}

test('bootstrap checks a skipped before phase after a handlerless dependency has fully returned', function (
    bool $shared,
    bool $preloaded,
): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'LatePolicy_' . $suffix;
    $target = 'Target_' . $suffix;
    $alias = __NAMESPACE__ . '\\' . $attribute;
    eval(sprintf('namespace %s; #[%s] final class %s extends Product {}', __NAMESPACE__, $attribute, $target));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the bootstrap dependency fixture.');
    }
    if ($preloaded) {
        class_alias(NoConstructor::class, $alias);
    }
    Product::$constructions = 0;
    $returned = null;
    $events = [];
    $builder = (new ContainerBuilder())->addAttributeDefinition(static function (Container $container) use (
        $class,
        $alias,
        $shared,
        &$returned,
        &$events,
    ): AttributeDefinition {
        $dependency = $shared ? $container->get($class) : $container->make($class);
        if (!$dependency instanceof Product) {
            throw new LogicException('Expected the completed bootstrap dependency.');
        }
        $returned = $dependency;
        $events[] = 'dependency returned';
        if (!class_exists($alias, false)) {
            class_alias(NoConstructor::class, $alias);
        }
        $events[] = 'policy available';

        return new AttributeDefinition(Extension::class);
    });

    if ($preloaded) {
        $container = $builder->build();
        expect($returned)->toBeInstanceOf(Product::class)
            ->and($returned?->constructed)->toBeFalse()
            ->and(Product::$constructions)->toBe(0)
            ->and($events)->toBe(['dependency returned', 'policy available']);
        $next = $container->get($class);
        expect($next)->toBeInstanceOf(Product::class);
        if (!$next instanceof Product) {
            throw new LogicException('Expected the published bootstrap dependency.');
        }
        expect($next->constructed)->toBeFalse()
            ->and(Product::$constructions)->toBe(0);
        if ($shared) {
            expect($next)->toBe($returned);
        }
        return;
    }

    expect($builder->build(...))->toThrow(InvalidConfigurationException::class, 'DI bootstrap used ' . $class)
        ->and($returned)->toBeInstanceOf(Product::class)
        ->and($returned?->constructed)->toBeTrue()
        ->and(Product::$constructions)->toBe(1)
        ->and($events)->toBe(['dependency returned', 'policy available']);
})->with(['get dependency' => true, 'make dependency' => false])
    ->with(['late policy' => false, 'preloaded control' => true]);
