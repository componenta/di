<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Lazy\CompletedCustomPropertyProxyContract;

use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionProperty;
use Reflector;

final class Trace
{
    public int $constructions = 0;
    public int $handlers = 0;

    public function __construct(public readonly string $alias) {}
}

abstract class Product
{
    public function __construct(Trace $trace)
    {
        ++$trace->constructions;
        if (!class_exists($trace->alias, false)) {
            class_alias(Proxy::class, $trace->alias);
        }
    }
}

final readonly class BeforeHandler implements AttributeHandlerInterface
{
    public function __construct(private Trace $trace) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof Proxy || !$target instanceof ReflectionProperty || $context->entry !== null) {
            throw new LogicException('Expected the custom property Proxy before construction.');
        }
        ++$this->trace->handlers;
    }
}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

test('a custom property Proxy discovered after its before phase rejects publication and remains usable on a fresh attempt', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'LateProxy_' . $suffix;
    $name = 'Product_' . $suffix;
    eval(sprintf(
        'namespace %s; final class %s extends Product { #[%s] public string $value = "ready"; }',
        __NAMESPACE__,
        $name,
        $attribute,
    ));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the completed before-phase fixture.');
    }
    $trace = new Trace(__NAMESPACE__ . '\\' . $attribute);
    $container = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addService(Trace::class, $trace)
        ->addAttributeDefinition(new AttributeDefinition(
            Proxy::class,
            new BeforeHandler($trace),
            rules: $uncached ? [new NoOpRule()] : [],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->build();

    expect($container->has($class))->toBeTrue()
        ->and($trace->constructions)->toBe(0)
        ->and($trace->handlers)->toBe(0)
        ->and(fn() => $container->get($class))->toThrow(AttributeCompositionException::class, 'BeforeInstantiation completed')
        ->and($trace->constructions)->toBe(1)
        ->and($trace->handlers)->toBe(0);

    $shared = $container->get($class);
    expect($shared)->toBeInstanceOf($class)
        ->and($container->get($class))->toBe($shared)
        ->and($trace->constructions)->toBe(2)
        ->and($trace->handlers)->toBe(1);

    expect($container->make($class))->not->toBe($shared)
        ->and($trace->constructions)->toBe(3)
        ->and($trace->handlers)->toBe(2);
})->with(['cached plans' => false, 'uncached plans' => true]);
