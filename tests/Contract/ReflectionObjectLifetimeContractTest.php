<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use AllowDynamicProperties;
use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use Reflector;
use stdClass;
use WeakReference;

#[Attribute(Attribute::TARGET_CLASS)]
final class ReflectionLifetimeMarker {}

/**
 * @property bool $first
 * @property bool $second
 */
#[AllowDynamicProperties, ReflectionLifetimeMarker]
final class ReflectionLifetimeTarget
{
    public function __construct(public object $payload = new stdClass()) {}
}

test('metadata preparation does not keep reflected objects alive', function (string $service, bool $objectReflection): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReflectionLifetimeMarker::class))
        ->build();
    $object = new ReflectionLifetimeTarget();
    $objectReference = WeakReference::create($object);
    $payloadReference = WeakReference::create($object->payload);
    $reflection = reflectionLifetimeTarget($object, $objectReflection);

    if ($service === 'attribute plans') {
        expect($container->get(AttributePlanBuilder::class)->build($reflection)->target)->toBe($reflection);
    } elseif ($service === 'attribute processor') {
        $container->get(AttributeProcessor::class)->prepare($reflection);
    } else {
        $container->get(ObjectPipeline::class)->prepare($reflection);
    }
    unset($reflection, $object);
    gc_collect_cycles();

    expect($objectReference->get())->toBeNull()
        ->and($payloadReference->get())->toBeNull();
})->with(['attribute plans', 'attribute processor', 'object pipeline'])
    ->with(['object reflection' => true, 'class reflection' => false]);

final class ReflectionPropertyObservationHandler implements AttributeHandlerInterface
{
    /** @var list<list<string>> */
    public array $properties = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionClass) {
            throw new LogicException('Expected the class reflection supplied to the object pipeline.');
        }
        $this->properties[] = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            $target->getProperties(),
        );
    }
}

test('object reflections keep their own metadata when class plans are already warm', function (bool $pipeline): void {
    $handler = new ReflectionPropertyObservationHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReflectionLifetimeMarker::class, $handler))
        ->build();
    $first = new ReflectionLifetimeTarget();
    $first->first = true;
    $second = new ReflectionLifetimeTarget();
    $second->second = true;
    $targets = [
        reflectionLifetimeTarget($first, false),
        reflectionLifetimeTarget($first, true),
        reflectionLifetimeTarget($second, true),
        reflectionLifetimeTarget($second, false),
    ];

    foreach ($targets as $target) {
        if ($pipeline) {
            $container->get(ObjectPipeline::class)->create($target);
        } else {
            $container->get(AttributeProcessor::class)->process(
                $target,
                AttributePhase::AfterInstantiation,
                new ObjectCreationContext($target),
            );
        }
    }

    expect($handler->properties)->toBe([
        ['payload'],
        ['payload', 'first'],
        ['payload', 'second'],
        ['payload'],
    ]);
})->with(['object pipeline' => true, 'attribute processor' => false]);

/** @return ReflectionClass<object> */
function reflectionLifetimeTarget(object $object, bool $objectReflection): ReflectionClass
{
    return $objectReflection ? new ReflectionObject($object) : new ReflectionClass($object);
}
