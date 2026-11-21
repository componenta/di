<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BootstrapRuntimeRetention;

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
use ReflectionProperty;
use Reflector;
use WeakReference;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Observed {}

final class Target
{
    #[Observed]
    public int $value = 42;
}

final class DynamicRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Recorder implements AttributeHandlerInterface
{
    /** @var list<WeakReference<ReflectionProperty>> */
    public array $targets = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionProperty) {
            throw new LogicException('Expected the observed property target.');
        }
        $this->targets[] = WeakReference::create($target);
    }
}

test('completed bootstrap does not retain transient targets from later uncached object phases', function (AttributePhase $phase): void {
    $recorder = new Recorder();
    $container = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        Observed::class,
        $recorder,
        rules: [new DynamicRule()],
        phase: $phase,
    ))->build();

    for ($index = 0; $index < 8; ++$index) {
        expect($container->makeAs(Target::class, Target::class)->value)->toBe(42);
    }
    gc_collect_cycles();

    expect($recorder->targets)->toHaveCount($phase === AttributePhase::Both ? 16 : 8)
        ->and(array_filter($recorder->targets, static fn(WeakReference $target): bool => $target->get() !== null))->toBe([]);
})->with([
    'before instantiation' => AttributePhase::BeforeInstantiation,
    'after instantiation' => AttributePhase::AfterInstantiation,
    'both phases' => AttributePhase::Both,
]);
