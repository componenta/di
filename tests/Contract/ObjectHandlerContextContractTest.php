<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
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
            $context->resolveProperty($value, static function () use ($context, $value): void {
                $context->writeProperty($value, 2);
                $context->resolveProperty($value, static fn() => $context->writeProperty($value, 3));
                throw new RuntimeException('The outer handler failed.');
            });
        } catch (Throwable $error) {
            $failure = $error;
        }

        expect($entry->value)->toBe(1)
            ->and($failure)->toBeInstanceOf(LogicException::class)
            ->and($failure?->getMessage())->toContain('is already being resolved.');

        $context->resolveProperty($value, static function () use ($context, $entry, $value, $other): void {
            $context->writeProperty($value, 4);
            $context->resolveProperty($other, static fn() => $context->writeProperty($other, 11));
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
