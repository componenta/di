<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StandaloneAttributeProcessor;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\ObjectAttributeAccess;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Marker {}

#[Marker]
final class Product
{
    public int $writes = 0;

    #[Marker]
    public string $value = 'initial' {
        set {
            ++$this->writes;
            $this->value = $value;
        }
    }

    #[Marker]
    public function hook(): void {}
}

final class UnrelatedProduct
{
    public string $value = 'unchanged';
}

final class Handler implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $kind = match (true) {
            $target instanceof ReflectionClass => 'class',
            $target instanceof ReflectionProperty => 'property',
            $target instanceof ReflectionMethod => 'method',
            default => throw new LogicException('Expected an object attribute target.'),
        };
        $this->events[] = ($context->entry === null ? 'before:' : 'after:') . $kind;
        if ($target instanceof ReflectionProperty && $context->entry !== null) {
            if (!$context->claimProperty($target)) {
                throw new LogicException('Expected an unclaimed writable property.');
            }
            $context->writeProperty($target, 'configured');
        }
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class CompletionMarker {}

#[Marker, CompletionMarker]
final class CompletionProduct {}

/** @extends ReflectionClass<object> */
final class CompletionReflection extends ReflectionClass
{
    public bool $published = false;

    public function __construct(
        private readonly AttributeDefinitionRegistry $registry,
        private readonly Handler $handler,
        private readonly AttributePhase $latePhase,
    ) {
        parent::__construct(CompletionProduct::class);
    }

    /** @return list<ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        if (!$this->published && $this->handler->events !== []) {
            $this->published = true;
            $this->registry->register(new AttributeDefinition(CompletionMarker::class, $this->handler, phase: $this->latePhase));
        }

        return parent::getAttributes($name, $flags);
    }
}

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function reflection(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}

test('a warmed processor sees later registrations in the registry that builds its plans', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $processor = new AttributeProcessor(new AttributePlanBuilder($registry));
    $reflection = reflection(Product::class);
    expect($processor->hasHandlers($reflection))->toBeFalse();

    $registry->register(new AttributeDefinition(Marker::class, new Handler()));

    expect($processor->hasHandlers($reflection))->toBeTrue();
});

test('standalone pipelines execute both object phases without an optional bootstrap service', function (): void {
    $handler = new Handler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class, $handler, phase: AttributePhase::Both));
    $plans = new AttributePlanBuilder($registry);
    $processor = new AttributeProcessor($plans);
    $pipeline = new ObjectPipeline($processor, new ParametersResolver($plans), new ProxyFactory());
    $reflection = reflection(Product::class);
    $processor->prepare($reflection);
    expect($processor->hasHandlers($reflection))->toBeTrue()->and($handler->events)->toBe([]);

    foreach ([1, 2] as $_attempt) {
        $handler->events = [];
        $entry = $pipeline->create($reflection);
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the reflected product.');
        }

        expect($entry->value)->toBe('configured')
            ->and($entry->writes)->toBe(1)
            ->and($handler->events)->toBe([
                'before:class', 'before:property', 'before:method',
                'after:property', 'after:class', 'after:method',
            ]);
    }
});

test('object attributes and property writes belong to the class in the creation context', function (): void {
    $handler = new Handler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class, $handler, phase: AttributePhase::AfterInstantiation));
    $processor = new AttributeProcessor(new AttributePlanBuilder($registry));
    $entry = new UnrelatedProduct();
    $attempt = ObjectCreationAccess::begin(new ReflectionClass($entry));
    $attempt->initialize($entry);
    $reflection = reflection(Product::class);

    expect(fn() => ObjectAttributeAccess::executor($processor)->process($reflection, AttributePhase::AfterInstantiation, $attempt->context))
        ->toThrow(InvalidConfigurationException::class, 'creation context')
        ->and(fn() => $attempt->context->claimProperty($reflection->getProperty('value')))
        ->toThrow(LogicException::class, 'does not belong')
        ->and($handler->events)->toBe([])
        ->and($entry->value)->toBe('unchanged');
});

test('the after phase requires an initialized entry before it can dispatch any handler', function (): void {
    $handler = new Handler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class, $handler));
    $processor = new AttributeProcessor(new AttributePlanBuilder($registry));
    $execution = ObjectAttributeAccess::executor($processor);
    $reflection = reflection(Product::class);
    $attempt = ObjectCreationAccess::begin($reflection);

    expect(fn() => $execution->process($reflection, AttributePhase::AfterInstantiation, $attempt->context))
        ->toThrow(LogicException::class, 'requires an initialized entry')
        ->and($handler->events)->toBe([]);

    $entry = new Product();
    $attempt->initialize($entry);
    $execution->process($reflection, AttributePhase::AfterInstantiation, $attempt->context);

    expect($entry->writes)->toBe(1)
        ->and($handler->events)->toBe(['after:property', 'after:class', 'after:method']);
});

test('object attribute execution validates changes discovered while completing its phase', function (AttributePhase $latePhase): void {
    $handler = new Handler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class, $handler, phase: AttributePhase::BeforeInstantiation));
    $processor = new AttributeProcessor(new AttributePlanBuilder($registry));
    $reflection = new CompletionReflection($registry, $handler, $latePhase);
    $context = ObjectCreationAccess::begin($reflection)->context;
    $execution = ObjectAttributeAccess::executor($processor);

    if ($latePhase === AttributePhase::BeforeInstantiation) {
        expect(fn() => $execution->process($reflection, AttributePhase::BeforeInstantiation, $context))
            ->toThrow(AttributeCompositionException::class);
    } else {
        $execution->process($reflection, AttributePhase::BeforeInstantiation, $context);
    }

    expect($reflection->published)->toBeTrue()
        ->and($handler->events)->toBe(['before:class']);
})->with([
    'changed the completed phase' => AttributePhase::BeforeInstantiation,
    'changed the other phase' => AttributePhase::AfterInstantiation,
]);
