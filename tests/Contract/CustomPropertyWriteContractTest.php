<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CustomPropertyWrite;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class State
{
    public static int $hooks = 0;
    public static int $writers = 0;
    public static ?Throwable $failure = null;
}

class Product
{
    #[EntryId('custom-property.value')]
    public private(set) string $value = 'unconfigured' {
        set {
            ++State::$hooks;
            if (State::$failure !== null) {
                throw State::$failure;
            }
            $this->value = strtoupper($value);
        }
    }

    #[EntryId('custom-property.hidden')]
    public private(set) string $hidden = 'untouched';
}

final class Child extends Product {}

class MetadataProperty extends ReflectionProperty
{
    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return $this->getName() === 'hidden' ? [] : parent::getAttributes($name, $flags);
    }
}

class WriterProperty extends MetadataProperty
{
    public function setValue(mixed $objectOrValue, mixed $value = null): void
    {
        if (!$objectOrValue instanceof Product || !is_string($value)) {
            throw new LogicException('Expected a property fixture and a string value.');
        }
        ++State::$writers;
        new ReflectionProperty(Product::class, $this->getName())->setValue($objectOrValue, 'custom:' . $value);
    }
}

final class InheritedWriterProperty extends WriterProperty {}

final class DiagnosticProperty extends MetadataProperty
{
    public function getName(): string
    {
        return parent::getName() === 'value' ? 'reportedValue' : parent::getName();
    }
}

/** @extends ReflectionClass<Child> */
final class PropertyClass extends ReflectionClass
{
    /** @param class-string<MetadataProperty> $propertyClass */
    public function __construct(
        private readonly string $propertyClass = MetadataProperty::class,
        private readonly bool $declaringScope = false,
    ) {
        parent::__construct(Child::class);
    }

    /** @return list<ReflectionProperty> */
    public function getProperties(?int $filter = null): array
    {
        $properties = [];
        foreach (parent::getProperties($filter) as $property) {
            $properties[] = new $this->propertyClass(
                $this->declaringScope ? $property->getDeclaringClass()->getName() : $this->getName(),
                $property->getName(),
            );
        }
        return $properties;
    }
}

function pipeline(): ObjectPipeline
{
    return (new ContainerBuilder())
        ->addService('custom-property.value', 'injected')
        ->addService('custom-property.hidden', 'configured')
        ->build()->get(ObjectPipeline::class);
}

beforeEach(function (): void {
    State::$hooks = 0;
    State::$writers = 0;
    State::$failure = null;
});

afterEach(function (): void {
    State::$failure = null;
});

test('custom property metadata keeps native private-set writes and remains isolated from warmed native plans', function (bool $customFirst, bool $declaringScope): void {
    $pipeline = pipeline();
    foreach ([$customFirst, !$customFirst, $customFirst] as $index => $custom) {
        $reflection = $custom ? new PropertyClass(declaringScope: $declaringScope) : new ReflectionClass(Child::class);
        $product = $pipeline->create($reflection);
        if (!$product instanceof Child) {
            throw new LogicException('Expected the reflected child product.');
        }

        expect($product->value)->toBe('INJECTED')
            ->and($product->hidden)->toBe($custom ? 'untouched' : 'configured')
            ->and(State::$hooks)->toBe($index + 1)
            ->and(State::$writers)->toBe(0);
    }
})->with(['custom first' => true, 'native first' => false])
    ->with(['child lookup scope' => false, 'declaring lookup scope' => true]);

test('custom property writer overrides keep ownership including an inherited override', function (string $propertyClass): void {
    if (!is_a($propertyClass, MetadataProperty::class, true)) {
        throw new LogicException('Expected a custom property reflector.');
    }
    $pipeline = pipeline();
    foreach ([1, 2] as $attempt) {
        $product = $pipeline->create(new PropertyClass($propertyClass));
        if (!$product instanceof Child) {
            throw new LogicException('Expected the custom writer product.');
        }

        expect($product->value)->toBe('CUSTOM:INJECTED')
            ->and($product->hidden)->toBe('untouched')
            ->and(State::$writers)->toBe($attempt)
            ->and(State::$hooks)->toBe($attempt);
    }
})->with([WriterProperty::class, InheritedWriterProperty::class]);

test('a native hook failure keeps custom property diagnostics and permits a subsequent creation', function (): void {
    $pipeline = pipeline();
    $cause = new RuntimeException('custom metadata hook failure');
    State::$failure = $cause;
    $failure = null;
    try {
        $pipeline->create(new PropertyClass(DiagnosticProperty::class));
    } catch (ResolutionException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ResolutionException::class);
    if (!$failure instanceof ResolutionException) {
        throw new LogicException('Expected the failing property hook.');
    }
    expect($failure->getPrevious())->toBe($cause)
        ->and($failure->propertyClass)->toBe(Product::class)
        ->and($failure->propertyName)->toBe('reportedValue')
        ->and(State::$hooks)->toBe(1);

    State::$failure = null;
    $product = $pipeline->create(new PropertyClass(DiagnosticProperty::class));
    if (!$product instanceof Child) {
        throw new LogicException('Expected the recovered property product.');
    }
    expect($product->value)->toBe('INJECTED')
        ->and($product->hidden)->toBe('untouched')
        ->and(State::$hooks)->toBe(2);
});
