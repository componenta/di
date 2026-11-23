<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\ObjectAttributeAccess;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionProperty;
use Reflector;
use RuntimeException;
use Throwable;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_CLASS)]
final class CreationContextExtension {}

#[CreationContextExtension]
final class CreationContextProduct
{
    public function __construct(public int $value) {}
}

#[CreationContextExtension]
final class CreationContextProperties
{
    public int $value = 1;
    public int $other = 10;
}

final readonly class CreationContextObserver implements AttributeHandlerInterface
{
    /** @param Closure(ObjectCreationContext):void $observe */
    public function __construct(private Closure $observe) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ($this->observe)($context);
    }
}

final class SuspendedContext
{
    public ?ObjectCreationContext $observed = null;
}

test('a suspended attribute handler keeps its constructor policy in its own execution', function (): void {
    $retained = new SuspendedContext();
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($retained): void {
        $retained->observed = $context;
        Fiber::suspend('before');
    });
    $container = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();
    $fiber = new Fiber(static fn(): object => $container->make(CreationContextProduct::class, ['value' => 7]));
    expect($fiber->start())->toBe('before');
    $context = $retained->observed ?? throw new LogicException('Expected an active context.');

    expect(static fn() => $context->disableConstructor())->toThrow(LogicException::class, 'execution');
    $fiber->resume();

    expect($fiber->getReturn())->toEqual(new CreationContextProduct(7));
});

test('another fiber cannot change a staged property while its handler is suspended', function (): void {
    $retained = new SuspendedContext();
    $property = new ReflectionProperty(CreationContextProperties::class, 'value');
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($retained, $property): void {
        $retained->observed = $context;
        $context->claimProperty($property);
        ObjectCreationAccess::withinProperty($context, $property, static function () use ($context, $property): void {
            $context->writeProperty($property, 2);
            Fiber::suspend('after');
        });
    });
    $container = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::AfterInstantiation))
        ->build();
    $fiber = new Fiber(static fn(): object => $container->make(CreationContextProperties::class));
    expect($fiber->start())->toBe('after');
    $context = $retained->observed ?? throw new LogicException('Expected an active context.');

    expect(static fn() => $context->writeProperty($property, 9))->toThrow(LogicException::class, 'execution');
    $fiber->resume();

    expect(fixtureInstance($fiber->getReturn(), CreationContextProperties::class)->value)->toBe(2);
});

#[Attribute(Attribute::TARGET_PROPERTY)]
final class StagedPropertyExtension {}

final class StagedPropertyProduct
{
    #[StagedPropertyExtension]
    public \stdClass $value;

    public int $committed = 7;
}

test('only the owning execution can read an uncommitted property value', function (): void {
    $retained = new SuspendedContext();
    $property = new ReflectionProperty(StagedPropertyProduct::class, 'value');
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($retained, $property): void {
        $retained->observed = $context;
        $pending = (object) ['label' => 'owner'];
        expect($context->claimProperty($property))->toBeTrue();
        $context->writeProperty($property, $pending);
        expect($context->readProperty($property))->toBe($pending);
        Fiber::suspend('staged');
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(StagedPropertyExtension::class, $handler, [ValueProvider::class]))
        ->build();
    $owner = new Fiber(static fn(): object => $container->make(StagedPropertyProduct::class));
    expect($owner->start())->toBe('staged');
    $context = $retained->observed ?? throw new LogicException('Expected a suspended property handler.');
    $read = static fn(): mixed => $context->readProperty($property);

    expect($property->isInitialized($context->entry))->toBeFalse()
        ->and($context->readProperty(new ReflectionProperty(StagedPropertyProduct::class, 'committed')))->toBe(7)
        ->and(static fn() => new Fiber($read)->start())->toThrow(LogicException::class, 'another execution');
    $owner->resume();

    $entry = fixtureInstance($owner->getReturn(), StagedPropertyProduct::class);
    expect($read())->toBe($entry->value)->and($entry->value->label)->toBe('owner');
});

test('object handlers materialize public parameters once and share them with the constructor', function (bool $deferred): void {
    $observed = [];
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use (&$observed): void {
        $observed = [$context->constructorEnabled, $context->parameters, $context->parameters];
    });
    $pipeline = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
    $calls = 0;
    $source = static function () use (&$calls): array {
        ++$calls;
        return ['value' => 7];
    };

    $entry = $deferred
        ? $pipeline->create(CreationContextProduct::class, resolveParameters: $source)
        : $pipeline->create(CreationContextProduct::class, ['value' => 7]);

    expect($entry)->toEqual(new CreationContextProduct(7))
        ->and($observed)->toBe([true, ['value' => 7], ['value' => 7]])
        ->and($calls)->toBe($deferred ? 1 : 0);
})->with([true, false]);

test('only the creation fiber can materialize deferred parameters while its handler is suspended', function (): void {
    $retained = new SuspendedContext();
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($retained): void {
        $retained->observed = $context;
        Fiber::suspend('before');
    });
    [, $runtime] = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->assemble();
    $fiber = new Fiber(static fn(): object => $runtime->objects->create(
        CreationContextProduct::class,
        resolveParameters: static fn(): array => ['value' => Fiber::getCurrent() === null ? 3 : 7],
    ));
    expect($fiber->start())->toBe('before');
    $context = $retained->observed ?? throw new LogicException('Expected an active context.');

    try {
        expect(static fn(): array => $context->parameters)->toThrow(LogicException::class, 'execution');
    } finally {
        $fiber->resume();
    }

    expect($fiber->getReturn())->toEqual(new CreationContextProduct(7));
});

test('a retained context cannot redispatch lifecycle handlers for a completed phase', function (): void {
    $retained = new SuspendedContext();
    $calls = 0;
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($retained, &$calls): void {
        $retained->observed = $context;
        ++$calls;
    });
    [$container, $runtime] = new ContainerBuilder()->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler))->assemble();
    $container->make(CreationContextProduct::class, ['value' => 7]);
    $context = $retained->observed ?? throw new LogicException('Expected a completed context.');

    expect(static fn() => ObjectAttributeAccess::executor($runtime->attributeProcessor)->process(
        new ReflectionClass(CreationContextProduct::class),
        AttributePhase::AfterInstantiation,
        $context,
    ))->toThrow(LogicException::class, 'phase');
    expect($calls)->toBe(1);
});

test('editing a copy of handler parameters with reference-valued slots cannot change the constructor arguments', function (): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context): void {
        $copy = $context->parameters;
        $copy['value'] = 11;
    });
    $container = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();
    $value = 7;

    expect($container->make(CreationContextProduct::class, ['value' => &$value]))->toEqual(new CreationContextProduct(7))
        ->and($value)->toBe(7);
});

test('object handlers may select the same creation strategy repeatedly', function (CreationStrategy $strategy): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($strategy): void {
        $context->selectStrategy($strategy);
        $context->selectStrategy($strategy);
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();

    expect(fixtureInstance($container->make(CreationContextProduct::class, ['value' => 9]), CreationContextProduct::class)->value)->toBe(9);
})->with(CreationStrategy::cases());

test('a creation strategy cannot change after a handler selects deferred construction', function (CreationStrategy $first, CreationStrategy $second): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($first, $second): void {
        $context->selectStrategy($first);
        $context->selectStrategy($second);
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();

    expect(fn() => $container->make(CreationContextProduct::class, ['value' => 9]))
        ->toThrow(InvalidConfigurationException::class, sprintf(
            'Creation strategies "%s" and "%s" cannot be combined for "%s".',
            $first->name,
            $second->name,
            CreationContextProduct::class,
        ));
})->with([
    [CreationStrategy::Lazy, CreationStrategy::Eager],
    [CreationStrategy::Proxy, CreationStrategy::Eager],
    [CreationStrategy::Lazy, CreationStrategy::Proxy],
    [CreationStrategy::Proxy, CreationStrategy::Lazy],
]);

test('object handlers cannot install an entry or reset the current creation attempt', function (): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context): void {
        $operations = get_class_methods($context);

        expect(array_intersect(['initialize', 'freshAttempt'], $operations))->toBe([])
            ->and($context->entry)->toBeNull();
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();

    expect($container->make(CreationContextProduct::class, ['value' => 9]))->toEqual(new CreationContextProduct(9));
});

test('same-property reentry preserves the original value and permits a new resolution', function (): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context): void {
        $entry = $context->entry;
        if (!$entry instanceof CreationContextProperties) {
            throw new LogicException('Expected an initialized creation context.');
        }
        $value = $context->class->getProperty('value');
        $other = $context->class->getProperty('other');
        expect($context->claimProperty($value))->toBeTrue()
            ->and($context->claimProperty($other))->toBeTrue();

        $failure = null;
        try {
            ObjectCreationAccess::withinProperty($context, $value, static function () use ($context, $value): void {
                $context->writeProperty($value, 2);
                ObjectCreationAccess::withinProperty($context, $value, static fn() => $context->writeProperty($value, 3));
                throw new RuntimeException('The outer handler failed.');
            });
        } catch (Throwable $error) {
            $failure = $error;
        }

        expect($entry->value)->toBe(1)
            ->and($failure)->toBeInstanceOf(LogicException::class)
            ->and($failure?->getMessage())->toContain('is already being resolved.');

        ObjectCreationAccess::withinProperty($context, $value, static function () use ($context, $entry, $value, $other): void {
            $context->writeProperty($value, 4);
            ObjectCreationAccess::withinProperty($context, $other, static fn() => $context->writeProperty($other, 11));
            expect($entry->value)->toBe(1)->and($entry->other)->toBe(11);
        });
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::AfterInstantiation))
        ->build();

    $entry = $container->make(CreationContextProperties::class);
    Assert::assertInstanceOf(CreationContextProperties::class, $entry);
    expect($entry->value)->toBe(4)->and($entry->other)->toBe(11);
});
