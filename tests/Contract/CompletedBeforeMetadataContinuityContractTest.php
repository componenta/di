<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompletedBeforeMetadataContinuity;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class BeforeConstruction {}

#[Attribute(Attribute::TARGET_CLASS)]
final class DiscoveredMetadata
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Events
{
    /** @var list<string> */
    public array $values = [];
}

abstract class Product
{
    public string $state = 'declared';

    public function __construct(string $alias, Events $events)
    {
        $events->values[] = 'constructor';
        $this->state = 'constructed';
        if (!class_exists($alias, false)) {
            class_alias(DiscoveredMetadata::class, $alias);
        }
    }
}

final readonly class RecordPhase implements AttributeHandlerInterface
{
    public function __construct(private Events $events) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->events->values[] = $context->entry === null ? 'before' : 'after';
    }
}

test('constructor discovery outside the completed before phase preserves its proof and executes pending work once', function (string $kind, bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'Discovered_' . $suffix;
    $target = 'Product_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s, BeforeConstruction] final class %s extends Product {}',
        __NAMESPACE__,
        $attribute,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the phase-continuity product.');
    }
    $events = new Events();
    $container = (new ContainerBuilder())
        ->addService(Events::class, $events)
        ->addAttributeDefinition(new AttributeDefinition(
            BeforeConstruction::class,
            new RecordPhase($events),
            rules: $uncached ? [new NoOpRule()] : [],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            DiscoveredMetadata::class,
            $kind === 'after handler' ? new RecordPhase($events) : null,
            phase: match ($kind) {
                'metadata before' => AttributePhase::BeforeInstantiation,
                'metadata both' => AttributePhase::Both,
                default => AttributePhase::AfterInstantiation,
            },
        ))
        ->build();
    DiscoveredMetadata::$constructions = 0;

    expect($container->has($class))->toBeTrue()
        ->and($events->values)->toBe([])
        ->and(DiscoveredMetadata::$constructions)->toBe(0);
    foreach ([1, 2] as $attempt) {
        $events->values = [];
        $entry = $container->make($class, ['alias' => __NAMESPACE__ . '\\' . $attribute]);
        expect($entry->state)->toBe('constructed')
            ->and($events->values)->toBe($kind === 'after handler' ? ['before', 'constructor', 'after'] : ['before', 'constructor'])
            ->and(DiscoveredMetadata::$constructions)->toBe($kind === 'after handler' ? $attempt : 0);
    }
})->with(['after handler', 'metadata before', 'metadata both'])
    ->with(['cached' => false, 'non-memoized' => true]);
