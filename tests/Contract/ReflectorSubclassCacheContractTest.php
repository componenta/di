<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ReflectorSubclassCache;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Internal\ObjectAttributeAccess;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use stdClass;
use WeakReference;

#[Attribute(Attribute::TARGET_ALL)]
final class Marker {}

#[Marker]
final class Product
{
    public bool $initialized = false;
}

final class MemberSubject
{
    #[Marker]
    public string $value = '';

    #[Marker]
    public function configure(#[Marker] string $value): void {}
}

trait FilteredAttributes
{
    public object $payload;
    public bool $hideAttributes = false;

    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return $this->hideAttributes ? [] : parent::getAttributes($name, $flags);
    }
}

/** @extends ReflectionClass<object> */
final class ClassReflection extends ReflectionClass
{
    use FilteredAttributes;
}

final class MethodReflection extends ReflectionMethod
{
    use FilteredAttributes;
}

final class PropertyReflection extends ReflectionProperty
{
    use FilteredAttributes;
}

final class ParameterReflection extends ReflectionParameter
{
    use FilteredAttributes;
}

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function nativeClass(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}

/** @return ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty */
function target(string $kind, bool $custom, bool $hideAttributes = true, ?object $payload = null): ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty
{
    if (!$custom) {
        return match ($kind) {
            'class' => nativeClass(Product::class),
            'method' => new ReflectionMethod(MemberSubject::class, 'configure'),
            'property' => new ReflectionProperty(MemberSubject::class, 'value'),
            'parameter' => new ReflectionParameter([MemberSubject::class, 'configure'], 'value'),
            default => throw new LogicException('Unknown reflector kind.'),
        };
    }

    $target = match ($kind) {
        'class' => new ClassReflection(Product::class),
        'method' => new MethodReflection(MemberSubject::class, 'configure'),
        'property' => new PropertyReflection(MemberSubject::class, 'value'),
        'parameter' => new ParameterReflection([MemberSubject::class, 'configure'], 'value'),
        default => throw new LogicException('Unknown reflector kind.'),
    };
    $target->hideAttributes = $hideAttributes;
    $target->payload = $payload ?? new stdClass();
    return $target;
}

test('custom reflectors and native metadata keep independent attribute plans in either warmup order', function (string $kind, bool $customFirst): void {
    [$container, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(Marker::class))
        ->assemble();
    $plans = $runtime->plans;
    Assert::assertInstanceOf(AttributePlanBuilder::class, $plans);

    foreach ([$customFirst, !$customFirst, $customFirst] as $custom) {
        $reflector = target($kind, $custom);
        $plan = $plans->build($reflector);

        expect($plan->usages)->toHaveCount($custom ? 0 : 1)
            ->and($plan->target)->not->toBe($reflector)
            ->and($plan->target->getName())->toBe($reflector->getName());
    }
})->with(['class', 'method', 'property', 'parameter'])
    ->with(['custom first' => true, 'native first' => false]);

final class InitializeHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$context->entry instanceof Product) {
            throw new LogicException('Expected the reflected product.');
        }
        $context->entry->initialized = true;
    }
}

test('custom class metadata does not disable or inherit native object handlers after warmup', function (bool $pipeline, bool $customFirst): void {
    [$container, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(Marker::class, new InitializeHandler()))
        ->assemble();

    foreach ([$customFirst, !$customFirst, $customFirst] as $custom) {
        $reflector = target('class', $custom);
        if (!$reflector instanceof ReflectionClass) {
            throw new LogicException('Expected a class reflector.');
        }
        if ($pipeline) {
            $product = $runtime->objects->create($reflector);
        } else {
            $product = new Product();
            $attempt = ObjectCreationAccess::begin($reflector);
            $context = $attempt->context;
            $attempt->initialize($product);
            ObjectAttributeAccess::executor($runtime->attributeProcessor)->process($reflector, AttributePhase::AfterInstantiation, $context);
        }
        if (!$product instanceof Product) {
            throw new LogicException('Expected the reflected product.');
        }

        expect($product->initialized)->toBe(!$custom);
    }
})->with(['processor' => false, 'pipeline' => true])
    ->with(['custom first' => true, 'native first' => false]);

test('metadata caches release state carried by custom class reflectors when their callers release them', function (string $service): void {
    [$container, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(Marker::class))
        ->assemble();
    $payload = new stdClass();
    $reference = WeakReference::create($payload);
    $reflector = target('class', true, false, $payload);
    if (!$reflector instanceof ReflectionClass) {
        throw new LogicException('Expected a class reflector.');
    }

    match ($service) {
        'plans' => $runtime->plans->build($reflector),
        'processor' => $runtime->attributeProcessor->prepare($reflector),
        'pipeline' => $runtime->objects->prepare($reflector),
        default => throw new LogicException('Unknown metadata service.'),
    };
    unset($reflector, $payload);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
})->with(['plans', 'processor', 'pipeline']);

final readonly class ConstructedProduct
{
    public function __construct(#[EntryId('reflector.value')] public string $value = 'default') {}
}

/** @extends ReflectionClass<ConstructedProduct> */
final class ConstructorClassReflection extends ReflectionClass
{
    public function __construct(private readonly object $payload)
    {
        parent::__construct(ConstructedProduct::class);
    }

    public function getConstructor(): ReflectionMethod
    {
        return new ConstructorMethodReflection($this->payload);
    }
}

final class ConstructorMethodReflection extends ReflectionMethod
{
    public function __construct(private readonly object $payload)
    {
        parent::__construct(ConstructedProduct::class, '__construct');
    }

    /** @return list<ReflectionParameter> */
    public function getParameters(): array
    {
        $parameter = new ParameterReflection([ConstructedProduct::class, '__construct'], 'value');
        $parameter->hideAttributes = true;
        $parameter->payload = $this->payload;
        return [$parameter];
    }
}

test('constructor plans keep custom parameter metadata isolated from native construction and release its payload', function (bool $customFirst): void {
    [$container, $runtime] = (new ContainerBuilder())->addService('reflector.value', 'service')->assemble();
    $pipeline = $runtime->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);

    foreach ([$customFirst, !$customFirst, $customFirst] as $custom) {
        $payload = new stdClass();
        $reference = WeakReference::create($payload);
        $reflector = $custom
            ? new ConstructorClassReflection($payload)
            : new ReflectionClass(ConstructedProduct::class);
        $product = $pipeline->create($reflector);
        if (!$product instanceof ConstructedProduct) {
            throw new LogicException('Expected a constructed product.');
        }
        expect($product->value)->toBe($custom ? 'default' : 'service');
        unset($reflector, $payload);
        gc_collect_cycles();

        expect($reference->get())->toBeNull();
    }
})->with(['custom first' => true, 'native first' => false]);
