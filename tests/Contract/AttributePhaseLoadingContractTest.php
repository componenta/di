<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionProperty;
use Reflector;
use WeakReference;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class LoadConstructorPolicy
{
    /** @var list<WeakReference<self>> */
    public static array $instances = [];

    public function __construct(string $alias)
    {
        self::$instances[] = WeakReference::create($this);
        if (!class_exists($alias, false)) {
            class_alias(NoConstructor::class, $alias);
        }
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class PriorConstructorPolicy {}

#[Attribute(Attribute::TARGET_CLASS)]
final class ConstructorPolicyExtension {}

final class ConstructorPolicyPreparation implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
    }
}

abstract class LateConstructorPolicyState
{
    public static int $constructions = 0;
    public string $state = 'default';

    public function __construct()
    {
        ++self::$constructions;
        $this->state = 'constructed';
    }
}

test('a constructor policy loaded by a member attribute applies before object instantiation', function (bool $sameTarget, bool $preloaded, bool $inherited = false, bool $bootstrap = false, bool $preceding = false): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'MemberLoadedNoConstructor_' . $suffix;
    $target = 'MemberLoadedPolicyTarget_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    $loader = sprintf('#[LoadConstructorPolicy(%s)]', var_export($fullAlias, true));
    $declaration = ($preceding ? '#[PriorConstructorPolicy]' : '') . ($sameTarget ? $loader : '') . '#[' . $alias . '] final class ' . $target
        . ' extends LateConstructorPolicyState { ' . ($sameTarget ? '' : $loader . ' public string $marker = "ready";') . ' }';
    $parent = 'MemberPolicyParent_' . $suffix;
    if ($inherited) {
        $declaration = 'class ' . $parent . ' extends LateConstructorPolicyState { ' . $loader . ' public string $marker = "ready"; }'
            . '#[' . $alias . '] final class ' . $target . ' extends ' . $parent . ' {}';
    }
    eval('namespace ' . __NAMESPACE__ . '; ' . $declaration);
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, LateConstructorPolicyState::class, true)) {
        throw new LogicException('Expected the constructor policy fixture.');
    }
    if ($preloaded) {
        class_alias(NoConstructor::class, $fullAlias);
    }
    LateConstructorPolicyState::$constructions = 0;
    LoadConstructorPolicy::$instances = [];
    $handler = new ConstructorPolicyPreparation();
    $previous = new ConstructorPolicyPreparation();
    $builder = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(PriorConstructorPolicy::class, $previous, before: [NoConstructor::class], phase: AttributePhase::BeforeInstantiation))
        ->addAttributeDefinition(new AttributeDefinition(
            LoadConstructorPolicy::class,
            $handler,
            before: [NoConstructor::class],
            phase: AttributePhase::BeforeInstantiation,
        ));
    $created = null;
    if ($bootstrap) {
        $builder->addAttributeDefinition(static function (Container $container) use ($class, &$created): AttributeDefinition {
            $created = $container->make($class);
            return new AttributeDefinition(ConstructorPolicyExtension::class);
        });
    }
    $container = $builder->build();

    if ($inherited) {
        expect($container->has(__NAMESPACE__ . '\\' . $parent))->toBeTrue()
            ->and(LoadConstructorPolicy::$instances)->toBe([]);
    }
    for ($iteration = 1; $iteration <= 2; ++$iteration) {
        $object = $iteration === 1 && $created !== null ? $created : $container->make($class);
        if (!$object instanceof LateConstructorPolicyState) {
            throw new LogicException('Expected the created object.');
        }
        expect($object->state)->toBe('default')
            ->and(LateConstructorPolicyState::$constructions)->toBe(0)
            ->and($handler->calls)->toBe($iteration)
            ->and($previous->calls)->toBe($preceding ? $iteration : 0)
            ->and(LoadConstructorPolicy::$instances)->toHaveCount($iteration)
            ->and(array_filter(LoadConstructorPolicy::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))
            ->toBe([]);
    }
})->with([
    'loaded on the class' => [true, false],
    'loaded on a property' => [false, false],
    'preloaded property control' => [false, true],
    'cached inherited property' => [false, false, true],
    'bootstrap dependency' => [false, false, false, true],
    'earlier class handler' => [false, false, false, false, true],
]);

test('a late class policy cannot move ahead of an executed member handler', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'TooLateConstructorPolicy_' . $suffix;
    $target = 'TooLatePolicyTarget_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[%s]
        final class %s extends LateConstructorPolicyState {
            #[PriorConstructorPolicy] public string $marker = 'ready';
            #[LoadConstructorPolicy(%s)] public function prepare(): void {}
        }
        PHP,
        __NAMESPACE__,
        $alias,
        $target,
        var_export(__NAMESPACE__ . '\\' . $alias, true),
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, LateConstructorPolicyState::class, true)) {
        throw new LogicException('Expected the late policy target.');
    }
    LateConstructorPolicyState::$constructions = 0;
    LoadConstructorPolicy::$instances = [];
    $previous = new ConstructorPolicyPreparation();
    $loader = new ConstructorPolicyPreparation();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(PriorConstructorPolicy::class, $previous, phase: AttributePhase::BeforeInstantiation))
        ->addAttributeDefinition(new AttributeDefinition(LoadConstructorPolicy::class, $loader, phase: AttributePhase::BeforeInstantiation))
        ->build();

    expect(fn() => $container->make($class))->toThrow(AttributeCompositionException::class, 'already executed')
        ->and(LateConstructorPolicyState::$constructions)->toBe(0)
        ->and($previous->calls)->toBe(1)
        ->and($loader->calls)->toBe(0)
        ->and(LoadConstructorPolicy::$instances)->toHaveCount(1)
        ->and(array_filter(LoadConstructorPolicy::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))
        ->toBe([]);

    $object = $container->make($class);
    if (!$object instanceof LateConstructorPolicyState) {
        throw new LogicException('Expected the fresh object after the alias became available.');
    }
    expect($object->state)->toBe('default')
        ->and(LateConstructorPolicyState::$constructions)->toBe(0)
        ->and($previous->calls)->toBe(2)
        ->and($loader->calls)->toBe(1);
});

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class PhasePropertyValue
{
    public function __construct(public string $suffix) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
final class LoadPropertyTransformation
{
    public static int $constructions = 0;

    public function __construct(string $alias)
    {
        ++self::$constructions;
        if (!class_exists($alias, false)) {
            class_alias(PhasePropertyValue::class, $alias);
        }
    }
}

abstract class PhaseReadonlyState
{
    abstract public string $value { get; }
}

final class PhasePropertyHandler implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];
    public ?PhaseReadonlyState $entry = null;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof LoadPropertyTransformation) {
            $this->events[] = 'loader';
            return;
        }
        if (!$attribute instanceof PhasePropertyValue || !$target instanceof ReflectionProperty
            || !$context->entry instanceof PhaseReadonlyState
        ) {
            throw new LogicException('Expected a readonly property transformer.');
        }
        if (!$context->propertyClaimed($target) && !$context->claimProperty($target)) {
            return;
        }
        $this->entry = $context->entry;
        $value = $context->readProperty($target) ?? 'input';
        if (!is_string($value)) {
            throw new LogicException('Expected string input.');
        }
        $this->events[] = $attribute->suffix;
        $context->writeProperty($target, $value . ':' . $attribute->suffix);
    }
}

test('late attributes cannot reopen a completed property transaction', function (bool $preloaded): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'CompletedPropertySuffix_' . $suffix;
    $target = 'CompletedPropertyTarget_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends PhaseReadonlyState {
            #[PhasePropertyValue('first'), %s('late')] public readonly string $value;
            #[LoadPropertyTransformation(%s)] public function after(): void {}
        }
        PHP,
        __NAMESPACE__,
        $target,
        $alias,
        var_export($fullAlias, true),
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, PhaseReadonlyState::class, true)) {
        throw new LogicException('Expected the readonly fixture.');
    }
    if ($preloaded) {
        class_alias(PhasePropertyValue::class, $fullAlias);
    }
    LoadPropertyTransformation::$constructions = 0;
    $handler = new PhasePropertyHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(PhasePropertyValue::class, $handler, [ValueTransformer::class]))
        ->addAttributeDefinition(new AttributeDefinition(LoadPropertyTransformation::class, $handler))
        ->build();

    if (!$preloaded) {
        expect(fn() => $container->make($class))->toThrow(AttributeCompositionException::class, 'property handlers completed')
            ->and($handler->events)->toBe(['first'])
            ->and($handler->entry?->value)->toBe('input:first')
            ->and(LoadPropertyTransformation::$constructions)->toBe(1);
        $handler->events = [];
    }

    $object = $container->make($class);
    if (!$object instanceof PhaseReadonlyState) {
        throw new LogicException('Expected the new readonly object.');
    }
    expect($object->value)->toBe('input:first:late')
        ->and($handler->events)->toBe(['first', 'late', 'loader'])
        ->and(LoadPropertyTransformation::$constructions)->toBe($preloaded ? 1 : 2);
})->with(['late' => false, 'preloaded' => true]);
