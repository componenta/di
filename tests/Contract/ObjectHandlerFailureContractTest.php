<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use Reflector;
use RuntimeException;

#[Attribute(Attribute::TARGET_ALL)]
final class HandlerFailureMarker {}

#[HandlerFailureMarker]
final class HandlerFailureClass {}

final class HandlerFailureProperty
{
    #[HandlerFailureMarker]
    public string $value = 'unchanged';
}

final class HandlerFailureMethod
{
    #[HandlerFailureMarker]
    public function configure(): void {}
}

final readonly class FailingObjectHandler implements AttributeHandlerInterface
{
    public function __construct(private RuntimeException $error) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        throw $this->error;
    }
}

test('object handler failures preserve their cause and identify the failed property or service', function (string $class, bool $property): void {
    if (!class_exists($class)) {
        throw new LogicException('Expected a handler failure fixture.');
    }
    $cause = new RuntimeException('custom handler failed');
    $handler = new FailingObjectHandler($cause);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(HandlerFailureMarker::class, $handler))
        ->build();

    try {
        $container->make($class);
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBe($cause)
            ->and($error->getMessage())->toBe($property
                ? 'Cannot resolve property "' . $class . '::$value": custom handler failed'
                : 'Failed to resolve service "' . $class . '": custom handler failed');
        return;
    }
    throw new LogicException('The failing handler should stop construction.');
})->with([
    [HandlerFailureClass::class, false],
    [HandlerFailureProperty::class, true],
    [HandlerFailureMethod::class, false],
]);

test('bootstrap rejects object members used before their attribute handler is registered', function (string $class): void {
    if (!class_exists($class)) {
        throw new LogicException('Expected a bootstrap member fixture.');
    }
    $handler = new FailingObjectHandler(new RuntimeException('must not run during bootstrap'));
    $builder = (new ContainerBuilder())
        ->addAttributeDefinition(static function (Container $container) use ($class, $handler): AttributeDefinition {
            $container->make($class);
            return new AttributeDefinition(HandlerFailureMarker::class, $handler);
        });

    expect(fn() => $builder->build())->toThrow(
        InvalidConfigurationException::class,
        'DI bootstrap used ' . $class . '::' . ($class === HandlerFailureProperty::class ? 'value' : 'configure')
            . ' before its required attribute extensions were registered. Register these extensions before factories that resolve this dependency.',
    );
})->with([HandlerFailureProperty::class, HandlerFailureMethod::class]);

test('standalone attribute processors distinguish declarative metadata from executable handlers', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(HandlerFailureMarker::class));
    $processor = new AttributeProcessor($registry, new AttributePlanBuilder($registry));
    $target = handlerFailureReflection(HandlerFailureClass::class);

    expect($processor->hasHandlers($target))->toBeFalse();
    $executableRegistry = new AttributeDefinitionRegistry();
    $executableRegistry->register(new AttributeDefinition(HandlerFailureMarker::class, new FailingObjectHandler(new RuntimeException('registered'))));
    $executable = new AttributeProcessor($executableRegistry, new AttributePlanBuilder($executableRegistry));
    expect($executable->hasHandlers($target))->toBeTrue();
});

test('attribute processors reject an ambiguous runtime phase', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $processor = new AttributeProcessor($registry, new AttributePlanBuilder($registry));
    $class = handlerFailureReflection(HandlerFailureClass::class);

    expect(fn() => $processor->process($class, AttributePhase::Both, new ObjectCreationContext($class)))
        ->toThrow(InvalidConfigurationException::class, 'AttributeProcessor::process() requires a concrete runtime phase.');
});

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function handlerFailureReflection(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}
