<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectCrossTargetPrefix;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final readonly class RecordTarget
{
    public function __construct(public string $label) {}
}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
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

final class DiscoverEarlierTarget implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly string $alias) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof RecordTarget) {
            throw new LogicException('Expected a target recording attribute.');
        }
        if (in_array($attribute->label, $this->events, true)) {
            throw new LogicException('A target handler must not be replayed in one creation attempt.');
        }
        $this->events[] = $attribute->label;
        if (!class_exists($this->alias, false)) {
            class_alias(RecordTarget::class, $this->alias);
        }
    }
}

test('a late earlier target cannot replace an executed prefix with the same attribute definition and declaration position', function (bool $before, bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'EarlierTarget_' . $suffix;
    $target = 'Product_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s("class")] final class %s extends Product { #[%s("property")] public string $marker = "declared"; }',
        __NAMESPACE__,
        $before ? $attribute : 'RecordTarget',
        $target,
        $before ? 'RecordTarget' : $attribute,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the cross-target prefix fixture.');
    }
    $handler = new DiscoverEarlierTarget(__NAMESPACE__ . '\\' . $attribute);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            RecordTarget::class,
            $handler,
            rules: $uncached ? [new NoOpRule()] : [],
            phase: $before ? AttributePhase::BeforeInstantiation : AttributePhase::AfterInstantiation,
        ))
        ->build();
    Product::$constructions = 0;

    expect($container->has($class))->toBeTrue()
        ->and($handler->events)->toBe([]);
    expect(fn(): object => $container->make($class))
        ->toThrow(AttributeCompositionException::class, 'already executed')
        ->and($handler->events)->toBe([$before ? 'property' : 'class'])
        ->and(Product::$constructions)->toBe($before ? 0 : 1);

    $handler->events = [];
    $entry = $container->makeAs($class, $class);
    expect($entry->value)->toBe('constructed')
        ->and($handler->events)->toBe($before ? ['class', 'property'] : ['property', 'class'])
        ->and(Product::$constructions)->toBe($before ? 1 : 2);
})->with(['before instantiation' => true, 'after instantiation' => false])
    ->with(['cached' => false, 'non-memoized' => true]);

abstract class SharedAttribute {}

#[Attribute(Attribute::TARGET_CLASS)]
final class OriginalAttribute extends SharedAttribute
{
    /** @var (Closure():void)|null */
    public static ?Closure $onInstantiation = null;

    public function __construct()
    {
        self::$onInstantiation?->__invoke();
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class ReplacementAttribute extends SharedAttribute {}

#[Attribute(Attribute::TARGET_CLASS)]
final class RefreshMarker {}

#[OriginalAttribute]
final class ReflectedProduct extends Product {}

#[ReplacementAttribute]
final class ReplacementMetadata {}

/** @extends ReflectionClass<object> */
final class SwitchingReflection extends ReflectionClass
{
    public bool $switched = false;

    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return $this->switched
            ? new ReflectionClass(ReplacementMetadata::class)->getAttributes($name, $flags)
            : parent::getAttributes($name, $flags);
    }
}

final class RecordConcreteAttribute implements AttributeHandlerInterface
{
    /** @var list<class-string> */
    public array $seen = [];

    /** @param (Closure():void)|null $refresh */
    public function __construct(private readonly ?Closure $refresh) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->seen[] = $attribute::class;
        $this->refresh?->__invoke();
    }
}

test('a refreshed slot cannot replace its concrete attribute after native instantiation or dispatch', function (bool $beforeDispatch): void {
    $registry = new AttributeDefinitionRegistry();
    $plans = new AttributePlanBuilder($registry);
    $objects = new ObjectPipeline($plans, new InstanceCreator(new ParametersResolver($plans)), new ProxyFactory(), $registry, new ObjectResolutionParameterStore());
    $reflection = new SwitchingReflection(ReflectedProduct::class);
    $refresh = static function () use ($reflection, $registry): void {
        if (!$reflection->switched) {
            $reflection->switched = true;
            $registry->register(new AttributeDefinition(RefreshMarker::class));
        }
    };
    $handler = new RecordConcreteAttribute($beforeDispatch ? null : $refresh);
    $registry->register(new AttributeDefinition(SharedAttribute::class, $handler, phase: AttributePhase::BeforeInstantiation));
    Product::$constructions = 0;
    OriginalAttribute::$onInstantiation = $beforeDispatch ? $refresh : null;

    try {
        expect(fn(): object => $objects->create($reflection))->toThrow(AttributeCompositionException::class)
            ->and($handler->seen)->toBe($beforeDispatch ? [] : [OriginalAttribute::class])
            ->and(Product::$constructions)->toBe(0);
    } finally {
        OriginalAttribute::$onInstantiation = null;
    }

    $handler->seen = [];
    expect($objects->create($reflection))->toBeInstanceOf(ReflectedProduct::class)
        ->and($handler->seen)->toBe([ReplacementAttribute::class])
        ->and(Product::$constructions)->toBe(1);
})->with(['native instantiation' => true, 'handler dispatch' => false]);
