<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StandaloneAttributeProcessor;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
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

test('standalone processors execute both object phases without an optional bootstrap service', function (): void {
    $handler = new Handler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class, $handler, phase: AttributePhase::Both));
    $processor = new AttributeProcessor($registry, new AttributePlanBuilder($registry));
    $reflection = reflection(Product::class);
    $processor->prepare($reflection);
    expect($processor->hasHandlers($reflection))->toBeTrue()->and($handler->events)->toBe([]);

    foreach ([1, 2] as $_attempt) {
        $handler->events = [];
        $attempt = ObjectCreationContext::begin($reflection);
        $context = $attempt->context;
        $processor->process($reflection, AttributePhase::BeforeInstantiation, $context);
        expect($handler->events)->toBe(['before:class', 'before:property', 'before:method']);

        $entry = new Product();
        $attempt->initialize($entry);
        $processor->process($reflection, AttributePhase::AfterInstantiation, $context);

        expect($entry->value)->toBe('configured')
            ->and($entry->writes)->toBe(1)
            ->and($handler->events)->toBe([
                'before:class', 'before:property', 'before:method',
                'after:property', 'after:class', 'after:method',
            ]);
    }
});

test('standalone processing validates changes discovered while completing its phase', function (AttributePhase $latePhase): void {
    $handler = new Handler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class, $handler, phase: AttributePhase::BeforeInstantiation));
    $processor = new AttributeProcessor($registry, new AttributePlanBuilder($registry));
    $reflection = new CompletionReflection($registry, $handler, $latePhase);
    $context = new ObjectCreationContext($reflection);

    if ($latePhase === AttributePhase::BeforeInstantiation) {
        expect(fn() => $processor->process($reflection, AttributePhase::BeforeInstantiation, $context))
            ->toThrow(AttributeCompositionException::class);
    } else {
        $processor->process($reflection, AttributePhase::BeforeInstantiation, $context);
    }

    expect($reflection->published)->toBeTrue()
        ->and($handler->events)->toBe(['before:class']);
})->with([
    'changed the completed phase' => AttributePhase::BeforeInstantiation,
    'changed the other phase' => AttributePhase::AfterInstantiation,
]);
