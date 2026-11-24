<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\ConfigKey;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use Reflector;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_CLASS)]
final class SemanticSnapshotFirst {}

#[Attribute(Attribute::TARGET_CLASS)]
final class SemanticSnapshotSecond {}

#[Attribute(Attribute::TARGET_CLASS)]
final class SemanticSnapshotOther {}

#[SemanticSnapshotFirst, SemanticSnapshotSecond]
final class SemanticSnapshotTarget
{
    /** @var list<class-string> */
    public array $events = [];
}

final class SemanticSnapshotHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$context->entry instanceof SemanticSnapshotTarget) {
            throw new LogicException('Expected the semantic snapshot target.');
        }
        $context->entry->events[] = $attribute::class;
    }
}

final class SemanticSnapshotRule implements AttributeCompositionRuleInterface
{
    public bool $reject = false;

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($this->reject) {
            throw new AttributeCompositionException('The original semantic snapshot rule rejected this target.');
        }
    }
}

it('owns all semantic definition array slots before caller references are rebound', function (): void {
    $capability = LifecycleHook::class;
    $required = $forbidden = $before = $after = SemanticSnapshotSecond::class;
    $originalRule = $rule = new SemanticSnapshotRule();
    $definition = new AttributeDefinition(
        SemanticSnapshotFirst::class,
        capabilities: [&$capability],
        requires: [&$required],
        forbids: [&$forbidden],
        before: [&$before],
        after: [&$after],
        rules: [&$rule],
    );

    $capability = ValueTransformer::class;
    $required = $forbidden = $before = $after = SemanticSnapshotOther::class;
    $rule = new SemanticSnapshotRule();

    expect($definition->capabilities)->toBe([LifecycleHook::class])
        ->and($definition->requires)->toBe([SemanticSnapshotSecond::class])
        ->and($definition->forbids)->toBe([SemanticSnapshotSecond::class])
        ->and($definition->before)->toBe([SemanticSnapshotSecond::class])
        ->and($definition->after)->toBe([SemanticSnapshotSecond::class])
        ->and($definition->rules)->toBe([$originalRule]);
});

it('preserves registered handler ordering after a selector reference changes', function (bool $warm, bool $uncached): void {
    $predecessor = SemanticSnapshotSecond::class;
    $container = container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(
                SemanticSnapshotFirst::class,
                new SemanticSnapshotHandler(),
                after: [&$predecessor],
                rules: $uncached ? [new SemanticSnapshotRule()] : [],
            ),
            new AttributeDefinition(SemanticSnapshotSecond::class, new SemanticSnapshotHandler()),
        ],
    ]);
    if ($warm) {
        expect($container->make(SemanticSnapshotTarget::class)->events)
            ->toBe([SemanticSnapshotSecond::class, SemanticSnapshotFirst::class]);
    }

    $predecessor = SemanticSnapshotOther::class;

    expect($container->make(SemanticSnapshotTarget::class)->events)
        ->toBe([SemanticSnapshotSecond::class, SemanticSnapshotFirst::class]);
})->with(['cold' => false, 'warm' => true])->with(['cached' => false, 'uncached' => true]);

it('retains the original composition rule identity and observes its current state', function (): void {
    $originalRule = $rule = new SemanticSnapshotRule();
    $container = container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(SemanticSnapshotFirst::class, rules: [&$rule]),
        ],
    ]);
    expect($container->make(SemanticSnapshotTarget::class))->toBeInstanceOf(SemanticSnapshotTarget::class);

    $rule = new SemanticSnapshotRule();
    $originalRule->reject = true;

    expect(fn() => $container->make(SemanticSnapshotTarget::class))
        ->toThrow(AttributeCompositionException::class, 'The original semantic snapshot rule rejected this target.');
});

it('captures semantic arrays before validation can execute autoloaders', function (): void {
    $attributeClass = __NAMESPACE__ . '\\SemanticSnapshotAutoloaded' . bin2hex(random_bytes(8));
    $required = SemanticSnapshotSecond::class;
    $loader = static function (string $class) use ($attributeClass, &$required): void {
        if ($class !== $attributeClass) {
            return;
        }
        $required = SemanticSnapshotOther::class;
        eval('namespace ' . __NAMESPACE__ . '; #[\\Attribute(\\Attribute::TARGET_CLASS)] final class '
            . substr($attributeClass, strrpos($attributeClass, '\\') + 1) . ' {}');
    };
    spl_autoload_register($loader, true, true);

    try {
        /** @var class-string $attributeClass */
        $definition = new AttributeDefinition($attributeClass, requires: [&$required]);

        expect($required)->toBe(SemanticSnapshotOther::class)
            ->and($definition->requires)->toBe([SemanticSnapshotSecond::class]);
    } finally {
        spl_autoload_unregister($loader);
    }
});
