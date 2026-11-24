<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\ConstructorPolicyLifecycleContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ConfigKey;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;
use WeakReference;

use function Componenta\DI\Tests\Support\assembledContainer;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
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

final class PropertyPolicyProduct extends Product
{
    #[InitializeWithoutConstructor]
    public string $label = 'property';

    private function __construct()
    {
        throw new LogicException('The property policy must bypass the private constructor.');
    }
}

final class MethodPolicyProduct extends Product
{
    private function __construct()
    {
        throw new LogicException('The method policy must bypass the private constructor.');
    }

    #[InitializeWithoutConstructor]
    public function marker(): void {}
}

final class InitializeWithoutConstructorHandler implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof InitializeWithoutConstructor) {
            throw new LogicException('Expected a constructor policy.');
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

test('constructor policies on class property and method targets qualify nonpublic constructors', function (
    string $class,
    AttributePhase $phase,
    bool $targeted,
): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a constructor-policy fixture.');
    }
    $handler = new InitializeWithoutConstructorHandler();
    [$container, $runtime] = assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(
            InitializeWithoutConstructor::class,
            $handler,
            capabilities: [ConstructorPolicy::class],
            phase: $phase,
        )],
    ], targetedDiscovery: $targeted);

    expect($runtime->objects->canCreate($class))->toBeTrue()
        ->and($container->has($class))->toBeTrue()
        ->and($container->has($class))->toBeTrue()
        ->and($handler->events)->toBe([]);

    $events = $phase === AttributePhase::Both ? ['before', 'after'] : ['before'];
    $state = $phase === AttributePhase::Both ? 'initialized' : 'uninitialized';
    $shared = $container->get($class);
    Assert::assertInstanceOf($class, $shared);
    expect($shared->state)->toBe($state)
        ->and($handler->events)->toBe($events)
        ->and($container->get($class))->toBe($shared)
        ->and($handler->events)->toBe($events);

    $fresh = $container->make($class);
    Assert::assertInstanceOf($class, $fresh);
    expect($fresh)->not->toBe($shared)
        ->and($fresh->state)->toBe($state)
        ->and($handler->events)->toBe([...$events, ...$events])
        ->and($container->get($class))->toBe($shared);
})->with([
    'class policy with private constructor' => PrivateProduct::class,
    'class policy with protected constructor' => ProtectedProduct::class,
    'property policy' => PropertyPolicyProduct::class,
    'method policy' => MethodPolicyProduct::class,
])->with([AttributePhase::BeforeInstantiation, AttributePhase::Both])
    ->with(['compatibility' => false, 'targeted' => true]);

test('only an executable before-instantiation constructor policy qualifies a nonpublic constructor', function (
    string $class,
    AttributePhase $phase,
    bool $hasCapability,
    bool $hasHandler,
): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a constructor-policy fixture.');
    }
    $handler = new InitializeWithoutConstructorHandler();
    [$container, $runtime] = assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(
            InitializeWithoutConstructor::class,
            $hasHandler ? $handler : null,
            capabilities: $hasCapability ? [ConstructorPolicy::class] : [],
            phase: $phase,
        )],
    ]);

    expect($runtime->objects->canCreate($class))->toBeFalse()
        ->and($container->has($class))->toBeFalse()
        ->and(fn() => $container->get($class))->toThrow(NotFoundException::class)
        ->and(fn() => $container->make($class))->toThrow(NotFoundException::class)
        ->and($handler->events)->toBe([]);
})->with([PrivateProduct::class, PropertyPolicyProduct::class, MethodPolicyProduct::class])
    ->with([
        'after phase' => [AttributePhase::AfterInstantiation, true, true],
        'no capability' => [AttributePhase::BeforeInstantiation, false, true],
        'no handler' => [AttributePhase::BeforeInstantiation, true, false],
    ]);

test('a discovered member constructor policy invalidates warmed eligibility', function (bool $method, bool $targeted): void {
    $suffix = bin2hex(random_bytes(5));
    $name = 'DiscoveredProduct_' . $suffix;
    $attribute = 'DiscoveredPolicy_' . $suffix;
    $member = $method ? 'public function marker(): void {}' : 'public string $label = "property";';
    eval(sprintf(
        'namespace %s; final class %s extends Product { #[%s] %s private function __construct() {} }',
        __NAMESPACE__,
        $name,
        $attribute,
        $member,
    ));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a constructor-policy fixture.');
    }
    $handler = new InitializeWithoutConstructorHandler();
    [$container, $runtime] = assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(
            InitializeWithoutConstructor::class,
            $handler,
            capabilities: [ConstructorPolicy::class],
            phase: AttributePhase::Both,
        )],
    ], targetedDiscovery: $targeted);
    expect($container->has($class))->toBeFalse()
        ->and($runtime->objects->canCreate($class))->toBeFalse()
        ->and($handler->events)->toBe([]);

    class_alias(InitializeWithoutConstructor::class, __NAMESPACE__ . '\\' . $attribute);
    expect($container->has($class))->toBeTrue()
        ->and($runtime->objects->canCreate($class))->toBeTrue()
        ->and($handler->events)->toBe([]);

    $entry = $container->make($class);
    Assert::assertInstanceOf($class, $entry);
    expect($entry->state)->toBe('initialized')
        ->and($handler->events)->toBe(['before', 'after']);
})->with(['property' => false, 'method' => true])
    ->with(['compatibility' => false, 'targeted' => true]);

test('a borrowed reflector selects its own member constructor policies without warming a native plan', function (string $class, bool $nativeFirst): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a constructor-policy fixture.');
    }
    $handler = new InitializeWithoutConstructorHandler();
    [, $runtime] = assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(
            InitializeWithoutConstructor::class,
            $handler,
            capabilities: [ConstructorPolicy::class],
            phase: AttributePhase::BeforeInstantiation,
        )],
    ]);
    if ($nativeFirst) {
        expect($runtime->objects->canCreate($class))->toBeTrue();
    }
    $borrowed = new class ($class) extends ReflectionClass {
        /** @return list<ReflectionProperty> */
        public function getProperties(?int $filter = null): array
        {
            return [];
        }

        /** @return list<ReflectionMethod> */
        public function getMethods(?int $filter = null): array
        {
            return [];
        }
    };
    $reference = WeakReference::create($borrowed);
    expect($runtime->objects->canCreate($borrowed))->toBeFalse();
    unset($borrowed);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($runtime->objects->canCreate($class))->toBeTrue()
        ->and($handler->events)->toBe([]);
})->with([PropertyPolicyProduct::class, MethodPolicyProduct::class])
    ->with(['borrowed first' => false, 'native first' => true]);

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
