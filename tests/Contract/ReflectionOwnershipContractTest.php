<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ReflectionOwnership;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
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
use stdClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class ObserveClass {}

#[ObserveClass]
final class Requested {}

final class Unrelated {}

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

test('a named plan cache does not retain an instance-specific dynamic property', function (): void {
    $first = (object) ['value' => 'first'];
    $second = (object) ['value' => 'second'];
    $builder = new AttributePlanBuilder(new AttributeDefinitionRegistry());
    $target = $builder->build(new ReflectionProperty($first, 'value'))->target;
    if (!$target instanceof ReflectionProperty) {
        throw new LogicException('Expected a property target.');
    }
    $target->__construct(HookTarget::class, 'value');

    $current = $builder->build(new ReflectionProperty($second, 'value'))->target;
    expect($current->getName())->toBe('value')
        ->and($current instanceof ReflectionProperty ? $current->getDeclaringClass()->getName() : null)
        ->toBe(stdClass::class);
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
