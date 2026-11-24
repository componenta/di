<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributeCompositionValidationContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ParameterOnlyAuditMarker {}

#[Attribute(Attribute::TARGET_ALL)]
final class CompositionFirst {}

#[Attribute(Attribute::TARGET_ALL)]
final class CompositionSecond {}

#[Attribute(Attribute::TARGET_ALL)]
final class CompositionMissing {}

#[CompositionFirst, CompositionSecond]
final class CompositionPair {}

final class CompositionMemberPair
{
    #[CompositionFirst, CompositionSecond]
    public string $value = '';
}

test('unsupported attribute targets report their actual target kind', function (string $kind): void {
    $name = 'InvalidTarget_' . bin2hex(random_bytes(5));
    $declaration = match ($kind) {
        'class' => sprintf('#[ParameterOnlyAuditMarker] final class %s {}', $name),
        'property' => sprintf('final class %s { #[ParameterOnlyAuditMarker] public string $value; }', $name),
        'method' => sprintf('final class %s { #[ParameterOnlyAuditMarker] public function run(): void {} }', $name),
        default => throw new \LogicException('Unknown fixture target.'),
    };
    eval(sprintf('namespace %s; %s', __NAMESPACE__, $declaration));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!class_exists($class)) {
        throw new \LogicException('The invalid attribute fixture was not declared.');
    }
    $target = match ($kind) {
        'property' => new ReflectionProperty($class, 'value'),
        'method' => new ReflectionMethod($class, 'run'),
        default => new ReflectionClass($class),
    };
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(ParameterOnlyAuditMarker::class));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        'Attribute "' . ParameterOnlyAuditMarker::class . '" cannot target ' . $kind . '.',
    );
})->with(['class', 'property', 'method']);

test('capability conflicts name every offending attribute in declaration order', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->defineCapability(new CapabilityPolicy(ValueProvider::class, 1));
    $registry->register(new AttributeDefinition(CompositionFirst::class, capabilities: [ValueProvider::class]));
    $registry->register(new AttributeDefinition(CompositionSecond::class, capabilities: [ValueProvider::class]));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        CompositionPair::class . ' accepts at most 1 attribute(s) with capability ' . ValueProvider::class
            . '; found #[' . CompositionFirst::class . '], #[' . CompositionSecond::class . '].',
    );
})->with([new ReflectionClass(CompositionPair::class)]);

test('a satisfied requirement does not hide a later missing requirement', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        CompositionFirst::class,
        requires: [CompositionSecond::class, CompositionMissing::class],
    ));
    $registry->register(new AttributeDefinition(CompositionSecond::class));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        CompositionPair::class . ' requires ' . CompositionMissing::class . ' because of #[' . CompositionFirst::class . '].',
    );
})->with([new ReflectionClass(CompositionPair::class)]);

test('an absent forbidden attribute does not hide a later present conflict', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        CompositionFirst::class,
        forbids: [CompositionMissing::class, CompositionSecond::class],
    ));
    $registry->register(new AttributeDefinition(CompositionSecond::class));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        CompositionPair::class . ' forbids ' . CompositionSecond::class . ' together with #[' . CompositionFirst::class . '].',
    );
})->with([new ReflectionClass(CompositionPair::class)]);

test('repeated ordering constraints produce one precedence relation', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(CompositionFirst::class, after: [CompositionSecond::class]));
    $registry->register(new AttributeDefinition(CompositionSecond::class, before: [CompositionFirst::class]));
    $plan = (new AttributePlanBuilder($registry))->build($target);

    expect(array_map(static fn(\Componenta\DI\Attribute\Composition\AttributeUsage $usage): string => $usage->attributeClass, $plan->usages))
        ->toBe([CompositionSecond::class, CompositionFirst::class]);
})->with([new ReflectionClass(CompositionPair::class)]);

final class CompositionDispatchHandler implements AttributeHandlerInterface
{
    /** @var list<class-string> */
    public array $events = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->events[] = $attribute::class;
    }
}

test('direct object preparation validates class and member compositions before dispatch', function (string $class, string $targetName): void {
    if (!class_exists($class, false)) {
        throw new LogicException('Expected the composition preparation fixture.');
    }
    $handler = new CompositionDispatchHandler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(CompositionFirst::class, $handler, requires: [CompositionMissing::class]));
    $registry->register(new AttributeDefinition(CompositionSecond::class, $handler));
    $processor = new AttributeProcessor(new AttributePlanBuilder($registry));

    expect(fn() => $processor->prepare(new ReflectionClass($class)))
        ->toThrow(AttributeCompositionException::class, $targetName . ' requires ' . CompositionMissing::class . ' because of #[' . CompositionFirst::class . '].')
        ->and($handler->events)->toBe([]);
})->with([
    'class' => [CompositionPair::class, CompositionPair::class],
    'property' => [CompositionMemberPair::class, CompositionMemberPair::class . '::$value'],
]);

test('valid attribute definitions reject ordering cycles before dispatch and accept an acyclic control', function (bool $after): void {
    $handler = new CompositionDispatchHandler();
    $first = new AttributeDefinition(
        CompositionFirst::class,
        $handler,
        before: $after ? [] : [CompositionSecond::class],
        after: $after ? [CompositionSecond::class] : [],
    );
    $container = (new ContainerBuilder())
        ->addAttributeDefinition($first)
        ->addAttributeDefinition(new AttributeDefinition(
            CompositionSecond::class,
            $handler,
            before: $after ? [] : [CompositionFirst::class],
            after: $after ? [CompositionFirst::class] : [],
        ))
        ->build();

    expect(fn() => $container->make(CompositionPair::class))
        ->toThrow(AttributeCompositionException::class, 'Attribute ordering for ' . CompositionPair::class . ' contains a cycle.')
        ->and($handler->events)->toBe([]);

    $control = (new ContainerBuilder())
        ->addAttributeDefinition($first)
        ->addAttributeDefinition(new AttributeDefinition(CompositionSecond::class, $handler))
        ->build();
    expect($control->make(CompositionPair::class))->toBeInstanceOf(CompositionPair::class)
        ->and($handler->events)->toBe($after
            ? [CompositionSecond::class, CompositionFirst::class]
            : [CompositionFirst::class, CompositionSecond::class]);
})->with(['before selectors' => false, 'after selectors' => true]);

final class MissingAttributeDeclaration {}

test('a registered ordinary class cannot execute as a DI attribute without its native marker', function (): void {
    $name = 'MissingAttributeTarget_' . bin2hex(random_bytes(6));
    eval(sprintf('namespace %s; #[MissingAttributeDeclaration] final class %s {}', __NAMESPACE__, $name));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!class_exists($class, false)) {
        throw new LogicException('Expected the invalid attribute fixture.');
    }
    $handler = new CompositionDispatchHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(MissingAttributeDeclaration::class, $handler))
        ->build();

    expect(fn() => $container->make($class))
        ->toThrow(AttributeCompositionException::class, 'Registered DI attribute "' . MissingAttributeDeclaration::class . '" is not declared with #[Attribute].')
        ->and($handler->events)->toBe([]);
});
