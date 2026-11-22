<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use AllowDynamicProperties;
use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Internal\ObjectAttributeAccess;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
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
    [$container, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReflectionLifetimeMarker::class))
        ->assemble();
    $object = new ReflectionLifetimeTarget();
    $objectReference = WeakReference::create($object);
    $payloadReference = WeakReference::create($object->payload);
    $reflection = reflectionLifetimeTarget($object, $objectReflection);

    if ($service === 'attribute plans') {
        expect($runtime->plans->build($reflection)->target->getName())->toBe($reflection->getName());
    } elseif ($service === 'attribute processor') {
        $runtime->attributeProcessor->prepare($reflection);
    } else {
        $runtime->objects->prepare($reflection);
    }
    unset($reflection, $object);
    gc_collect_cycles();

    expect($objectReference->get())->toBeNull()
        ->and($payloadReference->get())->toBeNull();
})->with(['attribute plans', 'attribute processor', 'object pipeline'])
    ->with(['object reflection' => true, 'class reflection' => false]);

test('a published attribute plan retains the declaration selected from an object reflector', function (): void {
    [, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReflectionLifetimeMarker::class))
        ->assemble();
    $source = new ReflectionObject(new ReflectionLifetimeTarget());
    $plan = $runtime->plans->build($source);

    new ReflectionMethod($plan->target, '__construct')->invoke($plan->target, new stdClass());

    expect($source->getName())->toBe(ReflectionLifetimeTarget::class)
        ->and($plan->target->getName())->toBe(ReflectionLifetimeTarget::class)
        ->and($plan->attributes(ReflectionLifetimeMarker::class))->toHaveCount(1);
});

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

test('post-construction handlers keep the selected declaration when the caller retargets its reflector', function (): void {
    $handler = new ReflectionPropertyObservationHandler();
    [, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReflectionLifetimeMarker::class, $handler, phase: AttributePhase::Both))
        ->assemble();
    $original = new ReflectionLifetimeTarget();
    $original->first = true;
    $source = new ReflectionObject($original);
    $payload = new stdClass();

    $entry = $runtime->objects->create($source, resolveParameters: static function () use ($source, $payload): array {
        new ReflectionMethod($source, '__construct')->invoke($source, new stdClass());
        return ['payload' => $payload];
    });

    expect($entry)->toEqual(new ReflectionLifetimeTarget($payload))
        ->and($handler->properties)->toBe([['payload', 'first'], ['payload']]);
});

test('object reflections keep their own metadata when class plans are already warm', function (bool $pipeline): void {
    $handler = new ReflectionPropertyObservationHandler();
    [$container, $runtime] = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReflectionLifetimeMarker::class, $handler))
        ->assemble();
    $first = new ReflectionLifetimeTarget();
    $first->first = true;
    $second = new ReflectionLifetimeTarget();
    $second->second = true;
    $targets = [
        [reflectionLifetimeTarget($first, false), $first],
        [reflectionLifetimeTarget($first, true), $first],
        [reflectionLifetimeTarget($second, true), $second],
        [reflectionLifetimeTarget($second, false), $second],
    ];

    foreach ($targets as [$target, $entry]) {
        if ($pipeline) {
            $runtime->objects->create($target);
        } else {
            $attempt = ObjectCreationAccess::begin($target);
            $attempt->initialize($entry);
            ObjectAttributeAccess::executor($runtime->attributeProcessor)->process($target, AttributePhase::AfterInstantiation, $attempt->context);
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
