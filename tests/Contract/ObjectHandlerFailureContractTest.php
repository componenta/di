<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Init;
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
use Throwable;

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

final class InitFailureState
{
    public ?Throwable $failure = null;
    public int $constructions = 0;
    public int $calls = 0;
    public int $writes = 0;
}

final class InitFailureProduct
{
    public function __construct(private InitFailureState $state)
    {
        ++$state->constructions;
    }

    #[Init([self::class, 'initialize'])]
    public string $value = 'unchanged' {
        set {
            ++$this->state->writes;
            $this->value = $value;
        }
    }

    public static function initialize(InitFailureState $state): string
    {
        ++$state->calls;
        if ($state->failure !== null) {
            throw $state->failure;
        }
        return 'configured';
    }
}

test('Init body failures preserve ownership without committing or publishing the failed object', function (bool $owned): void {
    $state = new InitFailureState();
    $cause = $owned ? new ResolutionException('owned initializer failure') : new RuntimeException('foreign initializer failure');
    $state->failure = $cause;
    $container = (new ContainerBuilder())->addService(InitFailureState::class, $state)->build();
    $failure = null;
    try {
        $container->get(InitFailureProduct::class);
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ResolutionException::class);
    if (!$failure instanceof ResolutionException) {
        throw new LogicException('Expected the initializer failure.');
    }
    if ($owned) {
        expect($failure)->toBe($cause)->and($failure->getPrevious())->toBeNull();
    } else {
        expect($failure->getPrevious())->toBe($cause)
            ->and($failure->propertyClass)->toBe(InitFailureProduct::class)
            ->and($failure->propertyName)->toBe('value')
            ->and($failure->propertyType)->toBe('string');
    }
    expect($state->constructions)->toBe(1)->and($state->calls)->toBe(1)->and($state->writes)->toBe(0);

    $state->failure = null;
    $product = $container->get(InitFailureProduct::class);
    expect($product->value)->toBe('configured')
        ->and($container->get(InitFailureProduct::class))->toBe($product)
        ->and($state->constructions)->toBe(2)
        ->and($state->calls)->toBe(2)
        ->and($state->writes)->toBe(1);
})->with(['owned DI exception' => true, 'foreign body exception' => false]);
