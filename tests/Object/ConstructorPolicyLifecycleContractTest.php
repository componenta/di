<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\ConstructorPolicyLifecycleContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionProperty;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class InitializeWithoutConstructor {}

abstract class Product
{
    public string $state = 'uninitialized';
}

#[InitializeWithoutConstructor]
final class PrivateProduct extends Product
{
    private function __construct()
    {
        throw new LogicException('The constructor policy must bypass the private constructor.');
    }
}

#[InitializeWithoutConstructor]
final class ProtectedProduct extends Product
{
    protected function __construct()
    {
        throw new LogicException('The constructor policy must bypass the protected constructor.');
    }
}

final class InitializeWithoutConstructorHandler implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof InitializeWithoutConstructor || !$target instanceof ReflectionClass) {
            throw new LogicException('Expected a class constructor policy.');
        }

        $entry = $context->entry;
        if ($entry === null) {
            $this->events[] = 'before';
            $context->disableConstructor();
            return;
        }
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the constructor-policy product.');
        }

        $this->events[] = 'after';
        $entry->state = 'initialized';
    }
}

test('a constructor policy active in both phases creates and initializes classes with nonpublic constructors', function (string $class): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a constructor-policy fixture.');
    }
    $handler = new InitializeWithoutConstructorHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            InitializeWithoutConstructor::class,
            $handler,
            capabilities: [ConstructorPolicy::class],
            phase: AttributePhase::Both,
        ))
        ->build();

    expect($container->has($class))->toBeTrue()
        ->and($handler->events)->toBe([]);

    $shared = $container->get($class);
    Assert::assertInstanceOf($class, $shared);
    expect($shared)->toBeInstanceOf($class)
        ->and($shared->state)->toBe('initialized')
        ->and($handler->events)->toBe(['before', 'after'])
        ->and($container->get($class))->toBe($shared)
        ->and($handler->events)->toBe(['before', 'after']);

    $fresh = $container->make($class);
    Assert::assertInstanceOf($class, $fresh);
    expect($fresh)->toBeInstanceOf($class)
        ->and($fresh)->not->toBe($shared)
        ->and($fresh->state)->toBe('initialized')
        ->and($handler->events)->toBe(['before', 'after', 'before', 'after'])
        ->and($container->get($class))->toBe($shared);
})->with([
    'private constructor' => PrivateProduct::class,
    'protected constructor' => ProtectedProduct::class,
]);

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class RetainContext {}

abstract class DeferredProduct
{
    public bool $constructed = false;

    #[RetainContext]
    public string $state = 'pending';

    public function __construct()
    {
        $this->constructed = true;
    }
}

#[Lazy, RetainContext]
final class LazyProduct extends DeferredProduct {}

#[Proxy, RetainContext]
final class ProxyProduct extends DeferredProduct {}

final class RetainContextHandler implements AttributeHandlerInterface
{
    public ?ObjectCreationContext $before = null;
    public ?ObjectCreationContext $after = null;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($target instanceof ReflectionClass && $context->entry === null) {
            $this->before = $context;
        }
        if ($target instanceof ReflectionProperty && $context->entry !== null) {
            $context->claimProperty($target);
            $context->writeProperty($target, 'configured');
            $this->after = $context;
        }
    }
}

test('retained attribute contexts cannot change a completed phase of deferred object creation', function (string $class): void {
    if (!is_a($class, DeferredProduct::class, true)) {
        throw new LogicException('Expected a deferred product.');
    }
    $handler = new RetainContextHandler();
    $container = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        RetainContext::class,
        $handler,
        phase: AttributePhase::Both,
    ))->build();
    $product = $container->make($class);
    Assert::assertInstanceOf(DeferredProduct::class, $product);
    $before = $handler->before ?? throw new LogicException('Expected the before context.');

    expect(fn() => $before->disableConstructor())->toThrow(LogicException::class)
        ->and(fn() => $before->selectStrategy($class === LazyProduct::class ? CreationStrategy::Lazy : CreationStrategy::Proxy))
        ->toThrow(LogicException::class)
        ->and($product->constructed)->toBeTrue()
        ->and($product->state)->toBe('configured');

    $after = $handler->after ?? throw new LogicException('Expected the after context.');
    $property = new ReflectionProperty($class, 'state');
    expect(fn() => $after->writeProperty($property, 'late'))->toThrow(LogicException::class)
        ->and($product->state)->toBe('configured');
})->with([LazyProduct::class, ProxyProduct::class]);
