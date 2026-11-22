<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PropertyReadHook;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionProperty;
use Reflector;
use RuntimeException;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ReadExisting {}

interface Product
{
    public string $value { get; set; }
    public int $reads { get; }
    public int $writes { get; }
}

final class BackedGetter implements Product
{
    private string $stored = 'initial';
    public int $reads = 0;
    public int $writes = 0;
    public static ?RuntimeException $failure = null;

    #[ReadExisting]
    public string $value {
        get {
            ++$this->reads;
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return $this->value ?? $this->stored;
        }
        set {
            ++$this->writes;
            $this->stored = $value;
            $this->value = $value;
        }
    }
}

final class VirtualGetter implements Product
{
    private string $stored = 'initial';
    public int $reads = 0;
    public int $writes = 0;

    #[ReadExisting]
    public string $value {
        get {
            ++$this->reads;
            return $this->stored;
        }
        set {
            ++$this->writes;
            $this->stored = $value;
        }
    }
}

final class PlainUninitialized implements Product
{
    public int $reads = 0;
    public int $writes = 0;

    #[ReadExisting]
    public string $value;
}

final class PendingValue
{
    public string $written = '';

    #[EntryId('source'), ReadExisting]
    public string $value {
        get => throw new LogicException('A pending value must take precedence over the getter.');
        set {
            $this->written = $value;
        }
    }
}

final class WriteOnly
{
    public string $written = '';

    #[ReadExisting]
    public string $value {
        set {
            $this->written = $value;
        }
    }
}

final readonly class ReadHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionProperty
            || (!$context->propertyClaimed($target) && !$context->claimProperty($target))
        ) {
            throw new LogicException('Expected a writable property.');
        }
        $value = $context->readProperty($target);
        if ($value !== null && !is_string($value)) {
            throw new LogicException('Expected the getter string or an uninitialized property.');
        }
        $context->writeProperty($target, $value === null ? 'fallback' : strtoupper($value));
    }
}

final readonly class UncachedRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function builder(bool $uncached): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ReadExisting::class,
            new ReadHandler(),
            [ValueTransformer::class],
            rules: $uncached ? [new UncachedRule()] : [],
        ));
}

test('custom property handlers read hooks even before their backing slot is initialized', function (
    string $class,
    string $expected,
    int $reads,
    int $writes,
    bool $uncached,
): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a property read fixture.');
    }
    $container = builder($uncached)->build();
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $entry = $container->make($class);
        Assert::assertInstanceOf($class, $entry);
        expect($entry->value)->toBe($expected)
            ->and($entry->reads)->toBe($reads)
            ->and($entry->writes)->toBe($writes);
    }
})->with([
    'uninitialized backing with getter' => [BackedGetter::class, 'INITIAL', 2, 1],
    'virtual getter' => [VirtualGetter::class, 'INITIAL', 2, 1],
    'uninitialized ordinary property' => [PlainUninitialized::class, 'fallback', 0, 0],
])->with(['cached' => false, 'uncached' => true]);

test('uninitialized backing does not suppress getter errors and a subsequent creation can recover', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $cause = new RuntimeException('getter is unavailable');
    BackedGetter::$failure = $cause;
    $failure = null;
    try {
        $container->make(BackedGetter::class);
    } catch (ResolutionException $error) {
        $failure = $error;
    } finally {
        BackedGetter::$failure = null;
    }

    expect($failure)->toBeInstanceOf(ResolutionException::class);
    if (!$failure instanceof ResolutionException) {
        throw new LogicException('The getter failure must remain observable.');
    }
    expect($failure->getPrevious())->toBe($cause)
        ->and($failure->propertyClass)->toBe(BackedGetter::class)
        ->and($failure->propertyName)->toBe('value');

    $entry = $container->make(BackedGetter::class);
    Assert::assertInstanceOf(BackedGetter::class, $entry);
    expect($entry->value)->toBe('INITIAL')
        ->and($entry->reads)->toBe(2)
        ->and($entry->writes)->toBe(1);
})->with(['cached' => false, 'uncached' => true]);

test('pending property values bypass getters and virtual write-only reads remain rejected', function (bool $uncached): void {
    $container = builder($uncached)->addService('source', 'pending')->build();

    expect(fixtureInstance($container->make(PendingValue::class), PendingValue::class)->written)->toBe('PENDING')
        ->and(fn() => $container->make(WriteOnly::class))->toThrow(ResolutionException::class, 'write-only virtual properties');
})->with(['cached' => false, 'uncached' => true]);
