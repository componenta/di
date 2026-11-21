<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectSequenceRefresh;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class OpenExtensions {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class InspectPolicy {}

#[Attribute(Attribute::TARGET_METHOD)]
final class ExtensionMarker {}

final class Trace
{
    /** @var list<string> */
    public static array $events = [];
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscoveredPolicy extends NoConstructor
{
    public function __construct()
    {
        Trace::$events[] = 'policy attribute';
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Tail
{
    public function __construct()
    {
        Trace::$events[] = 'tail attribute';
    }
}

final class State
{
    public bool $opened = false;
    public int $opens = 0;

    public function __construct(public readonly string $policy, public readonly string $marker) {}
}

final readonly class OpenHandler implements AttributeHandlerInterface
{
    public function __construct(private State $state) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof Tail) {
            Trace::$events[] = 'tail';
            return;
        }
        Trace::$events[] = 'open';
        $this->state->opened = true;
        ++$this->state->opens;
        if (!class_exists($this->state->marker, false)) {
            class_alias(ExtensionMarker::class, $this->state->marker);
        }
    }
}

final readonly class DiscoverPolicy implements AttributeCompositionRuleInterface
{
    public function __construct(private State $state) {}

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($this->state->opened && !class_exists($this->state->policy, false)) {
            class_alias(DiscoveredPolicy::class, $this->state->policy);
        }
    }
}

abstract class Product
{
    public static int $constructions = 0;
    public string $value = 'declared';

    public function __construct()
    {
        ++self::$constructions;
        $this->value = 'constructed';
    }
}

test('member rule discovery refreshes pending class policies without replaying completed handlers', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $policy = 'LatePolicy_' . $suffix;
    $marker = 'LateMarker_' . $suffix;
    $target = 'Target_' . $suffix;
    eval(sprintf(
        'namespace %s; #[OpenExtensions, %s, Tail] final class %s extends Product { #[InspectPolicy] public string $metadata = "value"; #[%s] public function hook(): void {} }',
        __NAMESPACE__,
        $policy,
        $target,
        $marker,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the object sequence fixture.');
    }
    $state = new State(__NAMESPACE__ . '\\' . $policy, __NAMESPACE__ . '\\' . $marker);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            OpenExtensions::class,
            new OpenHandler($state),
            before: [NoConstructor::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            Tail::class,
            new OpenHandler($state),
            after: [NoConstructor::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(InspectPolicy::class, rules: [new DiscoverPolicy($state)]))
        ->addAttributeDefinition(new AttributeDefinition(ExtensionMarker::class))
        ->build();
    Product::$constructions = 0;
    expect($container->has($class))->toBeTrue()->and($state->opens)->toBe(0);

    foreach ([1, 2] as $attempt) {
        Trace::$events = [];
        $entry = $container->makeAs($class, $class);
        expect($entry->value)->toBe('declared')
            ->and(Product::$constructions)->toBe(0)
            ->and($state->opens)->toBe($attempt)
            ->and(Trace::$events)->toBe(['open', 'policy attribute', 'tail attribute', 'tail']);
    }
});
