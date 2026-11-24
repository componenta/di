<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\ReflectionOwnershipContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PropertyHookType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class ObserveClass {}

#[ObserveClass]
final class Requested {}

final class Unrelated {}

/** @extends ReflectionClass<object> */
final class ApplicationReflection extends ReflectionClass {}

final class ApplicationParameter extends ReflectionParameter {}

#[Attribute(Attribute::TARGET_CLASS)]
final class OtherClassAttribute {}

#[OtherClassAttribute]
final class OtherClassWithHandler {}

#[Attribute(Attribute::TARGET_METHOD)]
final class ObserveStatic {}

class DeclaresStatic
{
    #[ObserveStatic]
    public static function scope(): string
    {
        return static::class;
    }
}

final class InheritsStatic extends DeclaresStatic {}

final class HookTarget
{
    public string $value {
        get => 'hook';
    }

    public function method(): string
    {
        return 'method';
    }
}

final class ClassObserver implements AttributeHandlerInterface
{
    /** @var list<ReflectionClass<covariant object>> */
    public array $observed = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionClass) {
            throw new LogicException('Expected a class reflector.');
        }
        $this->observed = [$target, $context->class];
    }
}

final class ParameterObserver implements ParameterResolverInterface
{
    public ?ReflectionParameter $observed = null;

    public function supports(ParameterTarget $target): bool
    {
        $this->observed = $target->reflection;
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

function answer(int $number = 10): int
{
    return $number;
}

function otherDefault(int $number = 20): int
{
    return $number;
}

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function classReflector(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}

test('reflectors retained by object handlers cannot retarget an independent container', function (): void {
    $observer = new ClassObserver();
    $first = new ContainerBuilder()->addAttributeDefinition(new AttributeDefinition(
        ObserveClass::class,
        $observer,
        phase: AttributePhase::BeforeInstantiation,
    ))->build();
    $second = new ContainerBuilder()->build();

    expect($first->make(Requested::class))->toBeInstanceOf(Requested::class);
    expect($observer->observed)->toHaveCount(2);
    foreach ($observer->observed as $reflector) {
        new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($reflector, Unrelated::class);
        expect($second->make(Requested::class))->toBeInstanceOf(Requested::class);
    }
});

test('the context reflection and the input reflector cannot retarget object creation', function (bool $deferred): void {
    $source = new ApplicationReflection(Requested::class);
    $handler = new class () implements AttributeHandlerInterface {
        /** @var class-string|null */
        public ?string $requested = null;

        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            $reflection = $context->class;
            new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($reflection, Unrelated::class);
            $this->requested = $context->class->getName();
        }
    };
    [, $runtime] = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(ObserveClass::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->assemble();

    $entry = $runtime->objects->create($source, resolveParameters: $deferred
        ? static function () use ($source): array {
            $source->__construct(Unrelated::class);
            return [];
        }
        : null);

    expect($entry)->toBeInstanceOf(Requested::class)
        ->and($handler->requested)->toBe(Requested::class)
        ->and($source->getName())->toBe($deferred ? Unrelated::class : Requested::class);
})->with(['immediate arguments' => false, 'deferred arguments' => true]);

test('a published class plan and usage own the declaration from a derived reflector', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(ObserveClass::class));
    $input = new ApplicationReflection(Requested::class);
    $plan = new AttributePlanBuilder($registry)->build($input);
    $published = $plan->target;
    $usage = $plan->attributes(ObserveClass::class)[0];
    new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($published, Unrelated::class);
    new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($usage->target, Unrelated::class);

    expect($input->getName())->toBe(Requested::class)
        ->and($plan->target->getName())->toBe(Requested::class)
        ->and($usage->target->getName())->toBe(Requested::class);
    $input->__construct(Unrelated::class);
    expect($plan->target->getName())->toBe(Requested::class)
        ->and($usage->target->getName())->toBe(Requested::class)
        ->and($usage->newInstance())->toBeInstanceOf(ObserveClass::class);
});

test('a class plan retains its declaration when a rule retargets the input reflector', function (bool $derived, bool $revised): void {
    $input = $derived ? new ApplicationReflection(Requested::class) : new ReflectionClass(Requested::class);
    $registry = new AttributeDefinitionRegistry();
    $rule = new class ($input, $revised ? $registry : null) implements AttributeCompositionRuleInterface {
        /** @param ReflectionClass<covariant object> $input */
        public function __construct(private readonly ReflectionClass $input, private ?AttributeDefinitionRegistry $registry) {}

        public function validate(AttributeUsage $attribute, AttributeSet $set): void
        {
            new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($this->input, OtherClassWithHandler::class);
            $this->registry?->register(new AttributeDefinition(ObserveStatic::class));
            $this->registry = null;
        }
    };
    $registry->register(new AttributeDefinition(ObserveClass::class, rules: [$rule]));
    $registry->register(new AttributeDefinition(OtherClassAttribute::class));

    $plan = new AttributePlanBuilder($registry)->build($input);

    expect($input->getName())->toBe(OtherClassWithHandler::class)
        ->and($plan->target->getName())->toBe(Requested::class)
        ->and($plan->usages[0]->target->getName())->toBe(Requested::class)
        ->and($plan->usages[0]->newInstance())->toBeInstanceOf(ObserveClass::class);
})->with(['native source' => false, 'derived source' => true])
    ->with(['stable definition registry' => false, 'revised definition registry' => true]);

test('a derived parameter keeps its declaration and default when a reflector is retargeted', function (bool $published): void {
    [, $runtime] = new ContainerBuilder()->assemble();
    $input = new ApplicationParameter(__NAMESPACE__ . '\\answer', 0);
    $target = new ParameterTarget($input);
    $view = $published ? $target->reflection : $input;
    $view->__construct(__NAMESPACE__ . '\\otherDefault', 0);

    expect($target->reflection->getDeclaringFunction()->getName())->toBe(__NAMESPACE__ . '\\answer')
        ->and($target->defaultValue)->toBe(10)
        ->and($runtime->parameters->resolveTargets([$target]))->toBe([10]);
})->with(['published snapshot' => true, 'borrowed source' => false]);

test('completed phases verify the selected class if deferred arguments retarget the reflector', function (): void {
    $handler = new ClassObserver();
    [, $runtime] = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(ObserveClass::class))
        ->addAttributeDefinition(new AttributeDefinition(OtherClassAttribute::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->assemble();
    $input = new ApplicationReflection(Requested::class);

    $entry = $runtime->objects->create($input, resolveParameters: static function () use ($input): array {
        $input->__construct(OtherClassWithHandler::class);
        return [];
    });

    expect($entry)->toBeInstanceOf(Requested::class)
        ->and($input->getName())->toBe(OtherClassWithHandler::class)
        ->and($handler->observed)->toBe([]);
});

test('bootstrap verifies the class its object phase actually used', function (bool $retarget): void {
    $runtime = new ResolutionRuntime();
    $input = new ApplicationReflection(Requested::class);
    $handler = new ClassObserver();
    $created = null;
    new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(ObserveClass::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->addAttributeDefinition(new AttributeDefinition(OtherClassAttribute::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->addAttributeDefinition(static function () use ($runtime, $input, $retarget, &$created): AttributeDefinition {
            $created = $runtime->objects->create($input, resolveParameters: static function () use ($input, $retarget): array {
                if ($retarget) {
                    $input->__construct(OtherClassWithHandler::class);
                }
                return [];
            });
            return new AttributeDefinition(ObserveStatic::class);
        })
        ->assemble($runtime);

    expect($created)->toBeInstanceOf(Requested::class)
        ->and($input->getName())->toBe($retarget ? OtherClassWithHandler::class : Requested::class)
        ->and(array_map(static fn(ReflectionClass $class): string => $class->getName(), $handler->observed))
        ->toBe([Requested::class, Requested::class]);
})->with(['stable input' => false, 'retargeted input' => true]);

test('a reflection parameter retained by supports cannot change the next call default', function (): void {
    $observer = new ParameterObserver();
    $container = new ContainerBuilder()->addParameterResolver($observer, -1000)->build();
    $function = __NAMESPACE__ . '\\answer';

    expect($container->call($function))->toBe(10);
    $observed = $observer->observed ?? throw new LogicException('Expected an observed parameter.');
    $observed->__construct(__NAMESPACE__ . '\\otherDefault', 0);

    expect($container->call($function))->toBe(10);
});

test('a named plan owns its declaration and publishes detached native reflectors', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(ObserveClass::class));
    $builder = new AttributePlanBuilder($registry);
    $reflection = classReflector(Requested::class);
    $plan = $builder->build($reflection);
    $target = $plan->target;
    if (!$target instanceof ReflectionClass || !isset($plan->usages[0])) {
        throw new LogicException('Expected a named class plan with one usage.');
    }

    $reflection->__construct(Unrelated::class);
    new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($target, Unrelated::class);
    $usageTarget = $plan->usages[0]->target;
    if (!$usageTarget instanceof ReflectionClass) {
        throw new LogicException('Expected a class usage.');
    }
    new ReflectionMethod(ReflectionClass::class, '__construct')->invoke($usageTarget, Unrelated::class);

    $cached = $builder->build(classReflector(Requested::class));
    expect($cached->target->getName())->toBe(Requested::class)
        ->and($cached->usages[0]->target->getName())->toBe(Requested::class);
});

test('a cached method plan can reconstruct a native property hook', function (): void {
    $hook = new ReflectionProperty(HookTarget::class, 'value')->getHook(PropertyHookType::Get)
        ?? throw new LogicException('Expected a reflected getter.');
    $builder = new AttributePlanBuilder(new AttributeDefinitionRegistry());
    $target = $builder->build($hook)->target;
    if (!$target instanceof ReflectionMethod) {
        throw new LogicException('Expected a method target.');
    }

    $target->__construct(HookTarget::class, 'method');

    expect($builder->build($hook)->target->getName())->toBe('$value::get');
});

test('attribute plans require a property declaration', function (): void {
    $object = (object) ['value' => 'dynamic'];
    $builder = new AttributePlanBuilder(new AttributeDefinitionRegistry());
    $dynamic = new ReflectionProperty($object, 'value');
    $declared = new ReflectionProperty(HookTarget::class, 'value');

    expect(fn() => $builder->build($dynamic))->toThrow(AttributeCompositionException::class, 'declared property')
        ->and(fn() => new AttributePlan($dynamic, []))->toThrow(AttributeCompositionException::class, 'declared property');

    $target = $builder->build($declared)->target;
    expect($target)->toBeInstanceOf(ReflectionProperty::class)
        ->and($target->getName())->toBe('value');
});

test('an inherited static method reflector preserves the creation class when dispatched to a handler', function (): void {
    $handler = new class () implements AttributeHandlerInterface {
        /** @var list<mixed> */
        public array $observed = [];

        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            if (!$target instanceof ReflectionMethod) {
                throw new LogicException('Expected the inherited method reflector.');
            }
            $this->observed[] = $target->invoke(null);
        }
    };
    $container = new ContainerBuilder()->addAttributeDefinition(new AttributeDefinition(ObserveStatic::class, $handler))->build();

    $container->make(InheritsStatic::class);
    $container->make(DeclaresStatic::class);
    $container->make(InheritsStatic::class);

    expect($handler->observed)->toBe([InheritsStatic::class, DeclaresStatic::class, InheritsStatic::class]);
});

test('a caller-owned native reflector is captured before an autoloader can retarget it', function (bool $construct): void {
    [, $runtime] = new ContainerBuilder()->assemble();
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'PendingAuditAttribute_' . $suffix;
    $trigger = __NAMESPACE__ . '\\RecordsUnavailableAttribute_' . $suffix;
    eval(sprintf('namespace %s; #[%s] final class RecordsUnavailableAttribute_%s {}', __NAMESPACE__, $attribute, $suffix));
    if (!class_exists($trigger, false)) {
        throw new LogicException('Expected the discovery fixture.');
    }
    $runtime->plans->build(classReflector($trigger));
    $requested = classReflector(Requested::class);
    $loader = static function (string $name) use ($requested, $attribute): void {
        if ($name === __NAMESPACE__ . '\\' . $attribute) {
            $requested->__construct(Unrelated::class);
        }
    };
    spl_autoload_register($loader);
    try {
        $actual = $construct ? $runtime->objects->create($requested)::class : $runtime->plans->build($requested)->target->getName();
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($requested->getName())->toBe(Unrelated::class)
        ->and($actual)->toBe(Requested::class);
})->with(['attribute plan' => false, 'standalone object pipeline' => true]);
