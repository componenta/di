<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StandaloneAttributeProcessor;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
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
        $context = new ObjectCreationContext($reflection);
        $processor->process($reflection, AttributePhase::BeforeInstantiation, $context);
        expect($handler->events)->toBe(['before:class', 'before:property', 'before:method']);

        $entry = new Product();
        $context->initialize($entry);
        $processor->process($reflection, AttributePhase::AfterInstantiation, $context);

        expect($entry->value)->toBe('configured')
            ->and($entry->writes)->toBe(1)
            ->and($handler->events)->toBe([
                'before:class', 'before:property', 'before:method',
                'after:property', 'after:class', 'after:method',
            ]);
    }
});
