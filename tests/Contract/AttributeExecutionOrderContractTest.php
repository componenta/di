<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionProperty;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class OrderedFirst {}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class OrderedSecond {}

final class AttributeOrderLog
{
    /** @var list<string> */
    public array $events = [];
}

final class OrderedHandler implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    /** @param Closure():void|null $load */
    public function __construct(
        private AttributeOrderLog $log,
        private string $label,
        private ?Closure $load = null,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!is_string($value->value)) {
            throw new LogicException('Expected a string transformer input.');
        }
        return ParameterAttributeValue::resolved($this->transform($value->value));
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($target instanceof ReflectionProperty) {
            $value = $context->readProperty($target);
            if (!is_string($value)) {
                throw new LogicException('Expected a string property.');
            }
            $context->writeProperty($target, $this->transform($value));
            return;
        }
        $this->transform('');
    }

    private function transform(string $value): string
    {
        $this->log->events[] = $this->label;
        $this->load?->__invoke();
        $this->load = null;
        return $value . ':' . $this->label;
    }
}

abstract class OrderedResult
{
    public string $value;
}

it('rejects a late predecessor before executing it or replaying completed parameter handlers', function (bool $constructor, bool $bootstrap = false): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateOrderedFirst_' . $suffix;
    $target = 'OrderedParameter_' . $suffix;
    $declaration = $constructor
        ? 'public function __construct(#[OrderedSecond, %s] string $value) { $this->value = $value; }'
        : 'public static function read(#[OrderedSecond, %s] string $value): string { return $value; }';
    eval(sprintf('namespace %s; final class %s extends OrderedResult { %s }', __NAMESPACE__, $target, sprintf($declaration, $attribute)));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the parameter fixture.');
    }
    $log = new AttributeOrderLog();
    $load = static function () use ($attribute): void {
        class_alias(OrderedFirst::class, __NAMESPACE__ . '\\' . $attribute);
    };
    $builder = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(OrderedFirst::class, new OrderedHandler($log, 'first'), [ValueTransformer::class], before: [OrderedSecond::class]))
        ->addAttributeDefinition(new AttributeDefinition(OrderedSecond::class, new OrderedHandler($log, 'second', $load), [ValueTransformer::class]));
    if ($bootstrap) {
        $builder->addAttributeDefinition(static function (Container $container) use ($constructor, $class): AttributeDefinition {
            if ($constructor) {
                $container->make($class, ['value' => 'value']);
            } else {
                $container->call([$class, 'read'], ['value' => 'value']);
            }
            return new AttributeDefinition(OrderedBootstrapExtension::class);
        });
        expect(fn() => $builder->build())->toThrow(InvalidConfigurationException::class, 'already executed')
            ->and($log->events)->toBe(['second']);
        return;
    }
    $container = $builder->build();
    $resolve = static fn(): mixed => $constructor
        ? $container->make($class, ['value' => 'value'])
        : $container->call([$class, 'read'], ['value' => 'value']);

    expect($resolve)->toThrow(AttributeCompositionException::class)
        ->and($log->events)->toBe(['second']);

    $result = $resolve();
    expect($result instanceof OrderedResult ? $result->value : $result)->toBe('value:first:second')
        ->and($log->events)->toBe(['second', 'first', 'second']);
})->with([
    'constructor' => [true],
    'callable' => [false],
    'bootstrap constructor' => [true, true],
    'bootstrap callable' => [false, true],
]);


#[Attribute(Attribute::TARGET_CLASS)]
final class OrderedBootstrapExtension {}

it('rejects late object predecessors including dependencies created during bootstrap', function (string $kind, bool $bootstrap): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateObjectFirst_' . $suffix;
    $target = 'OrderedObject_' . $suffix;
    $attributes = sprintf('#[OrderedSecond, %s]', $attribute);
    $declaration = match ($kind) {
        'class' => $attributes . ' final class %s {}',
        'property' => 'final class %s { #[\Componenta\DI\Attribute\EntryId("value")] ' . $attributes . ' public string $value; }',
        'method' => 'final class %s { ' . $attributes . ' public function hook(): void {} }',
        default => throw new LogicException('Unknown object target kind.'),
    };
    eval(sprintf('namespace %s; %s', __NAMESPACE__, sprintf($declaration, $target)));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the object fixture.');
    }
    $log = new AttributeOrderLog();
    $load = static function () use ($attribute): void {
        class_alias(OrderedFirst::class, __NAMESPACE__ . '\\' . $attribute);
    };
    $builder = (new ContainerBuilder())
        ->addService('value', 'value')
        ->addAttributeDefinition(new AttributeDefinition(OrderedFirst::class, new OrderedHandler($log, 'first'), [ValueTransformer::class], before: [OrderedSecond::class], after: [ValueProvider::class]))
        ->addAttributeDefinition(new AttributeDefinition(OrderedSecond::class, new OrderedHandler($log, 'second', $load), [ValueTransformer::class], after: [ValueProvider::class]));

    if ($bootstrap) {
        $builder->addAttributeDefinition(static function (Container $container) use ($class): AttributeDefinition {
            $container->get($class);
            return new AttributeDefinition(OrderedBootstrapExtension::class);
        });
        expect(fn() => $builder->build())->toThrow(InvalidConfigurationException::class, 'already executed')
            ->and($log->events)->toBe(['second']);
        return;
    }

    $container = $builder->build();
    expect(fn() => $container->get($class))->toThrow(AttributeCompositionException::class)
        ->and($log->events)->toBe(['second']);

    $container->get($class);
    expect($log->events)->toBe(['second', 'first', 'second']);
})->with([
    'class' => ['class', false],
    'property' => ['property', false],
    'method' => ['method', false],
    'bootstrap class' => ['class', true],
    'bootstrap property' => ['property', true],
    'bootstrap method' => ['method', true],
]);

#[OrderedFirst, OrderedSecond]
final class PhasedOrderTarget
{
    public function __construct(AttributeOrderLog $log)
    {
        $log->events[] = 'constructor';
    }
}

it('rejects ordering that contradicts object phases before any handler or constructor runs', function (bool $beforeSelector): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            after: $beforeSelector ? [] : [OrderedSecond::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            before: $beforeSelector ? [OrderedFirst::class] : [],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    expect(fn() => $container->make(PhasedOrderTarget::class))
        ->toThrow(AttributeCompositionException::class, 'execution phases')
        ->and($log->events)->toBe([]);
})->with(['before selector' => true, 'after selector' => false]);
it('orders handlers that participate in both object phases within each phase', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            before: [OrderedFirst::class],
            phase: AttributePhase::Both,
        ))
        ->build();

    $container->make(PhasedOrderTarget::class);

    expect($log->events)->toBe(['second', 'first', 'constructor', 'second']);
});

it('keeps parameter ordering independent of the handlers object phases', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            [ValueTransformer::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            [ValueTransformer::class],
            before: [OrderedFirst::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    $result = $container->call(static fn(#[OrderedFirst, OrderedSecond] string $value): string => $value, ['value' => 'value']);

    expect($result)->toBe('value:second:first')->and($log->events)->toBe(['second', 'first']);
});

it('does not impose execution phases on metadata without a runtime handler', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            before: [OrderedFirst::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    $container->make(PhasedOrderTarget::class);

    expect($log->events)->toBe(['constructor', 'second']);
});

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
final class PhaseOrderMarker {}

#[OrderedFirst, PhaseOrderMarker, OrderedSecond]
final class MetadataPhaseOrderTarget
{
    public function __construct(AttributeOrderLog $log)
    {
        $log->events[] = 'constructor';
    }
}

it('rejects a phase conflict through metadata before handlers or the constructor run', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            after: [PhaseOrderMarker::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(PhaseOrderMarker::class, after: [OrderedSecond::class]))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    expect(fn() => $container->make(MetadataPhaseOrderTarget::class))
        ->toThrow(AttributeCompositionException::class, 'execution phases')
        ->and($log->events)->toBe([]);
});
#[Attribute(Attribute::TARGET_CLASS)]
final class PhaseOrderSecondMarker {}

#[OrderedFirst, PhaseOrderMarker, PhaseOrderSecondMarker, OrderedSecond]
final class LongerMetadataPhaseOrderTarget
{
    public function __construct(AttributeOrderLog $log)
    {
        $log->events[] = 'constructor';
    }
}

it('rejects reversed phases across multiple metadata attributes regardless of their phase labels', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            PhaseOrderMarker::class,
            before: [PhaseOrderSecondMarker::class],
            phase: AttributePhase::Both,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            PhaseOrderSecondMarker::class,
            before: [OrderedFirst::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            before: [PhaseOrderMarker::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    expect(fn() => $container->make(LongerMetadataPhaseOrderTarget::class))
        ->toThrow(AttributeCompositionException::class, 'execution phases')
        ->and($log->events)->toBe([]);
});

it('preserves valid ordering through metadata when the attribute plan is reused', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            before: [PhaseOrderMarker::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(PhaseOrderMarker::class, before: [OrderedSecond::class]))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    $container->make(MetadataPhaseOrderTarget::class);
    expect($log->events)->toBe(['first', 'constructor', 'second']);

    $log->events = [];
    $container->make(MetadataPhaseOrderTarget::class);
    expect($log->events)->toBe(['first', 'constructor', 'second']);
});

it('allows a Both handler to satisfy ordering in its separate phase invocations', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addService(AttributeOrderLog::class, $log)
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            PhaseOrderMarker::class,
            new OrderedHandler($log, 'bridge'),
            before: [OrderedFirst::class],
            phase: AttributePhase::Both,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            before: [PhaseOrderMarker::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    $container->make(MetadataPhaseOrderTarget::class);

    expect($log->events)->toBe(['bridge', 'first', 'constructor', 'second', 'bridge']);
});

it('orders parameter transformers through metadata independently of object phases', function (): void {
    $log = new AttributeOrderLog();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedFirst::class,
            new OrderedHandler($log, 'first'),
            [ValueTransformer::class],
            after: [PhaseOrderMarker::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(PhaseOrderMarker::class, after: [OrderedSecond::class]))
        ->addAttributeDefinition(new AttributeDefinition(
            OrderedSecond::class,
            new OrderedHandler($log, 'second'),
            [ValueTransformer::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    $result = $container->call(
        static fn(#[OrderedFirst, PhaseOrderMarker, OrderedSecond] string $value): string => $value,
        ['value' => 'value'],
    );

    expect($result)->toBe('value:second:first')
        ->and($log->events)->toBe(['second', 'first']);
});
