<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;
use Reflector;
use WeakReference;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BeforeLoadedRequest {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LoadRequestAlias
{
    /** @var list<WeakReference<self>> */
    public static array $instances = [];

    public function __construct(public string $alias, public bool $duringHandler = false)
    {
        self::$instances[] = WeakReference::create($this);
        if (!$duringHandler) {
            $this->load();
        }
    }

    public function load(): void
    {
        if (!class_exists($this->alias, false)) {
            class_alias(CurrentRequest::class, $this->alias);
        }
    }
}

final class ObserveRequestInput implements ParameterAttributeHandlerInterface
{
    /** @var list<bool> */
    public array $resolvedInputs = [];

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        $this->resolvedInputs[] = $value->resolved;
        if ($attribute instanceof LoadRequestAlias && $attribute->duringHandler) {
            $attribute->load();
        }
        return $value;
    }
}

test('constructor-loaded CurrentRequest cannot change input policy after an earlier handler consumed input', function (bool $preloaded): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'ConstructorCurrentRequest_' . $suffix;
    $function = 'readConstructorRequest_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        function %s(
            #[BeforeLoadedRequest, LoadRequestAlias(%s), %s] \Psr\Http\Message\ServerRequestInterface $value,
        ): \Psr\Http\Message\ServerRequestInterface { return $value; }
        PHP,
        __NAMESPACE__,
        $function,
        var_export($fullAlias, true),
        $alias,
    ));
    if ($preloaded) {
        class_alias(CurrentRequest::class, $fullAlias);
    }
    LoadRequestAlias::$instances = [];
    $observer = new ObserveRequestInput();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            BeforeLoadedRequest::class,
            $observer,
            [ValueTransformer::class],
            before: [CurrentRequest::class, LoadRequestAlias::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            LoadRequestAlias::class,
            $observer,
            [ValueTransformer::class],
            after: [CurrentRequest::class],
        ))
        ->build();

    if (!$preloaded) {
        expect(fn() => $container->call(__NAMESPACE__ . '\\' . $function, [
            'value' => new ServerRequest('GET', '/caller'),
            ServerRequestInterface::class => new ServerRequest('GET', '/actual'),
        ]))->toThrow(AttributeCompositionException::class, 'input policy')
            ->and($observer->resolvedInputs)->toBe([true])
            ->and(LoadRequestAlias::$instances)->toHaveCount(1)
            ->and(array_filter(LoadRequestAlias::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))
            ->toBe([]);
        $observer->resolvedInputs = [];
        LoadRequestAlias::$instances = [];
    }
    foreach (['value', 0] as $key) {
        $request = new ServerRequest('GET', '/actual');
        $caller = new ServerRequest('GET', '/caller');
        expect($container->call(__NAMESPACE__ . '\\' . $function, [
            $key => $caller,
            ServerRequestInterface::class => $request,
        ]))->toBe($request);
    }

    expect($observer->resolvedInputs)->toBe([false, true, false, true])
        ->and(LoadRequestAlias::$instances)->toHaveCount(2)
        ->and(array_filter(LoadRequestAlias::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))
        ->toBe([]);
})->with(['late alias' => false, 'preloaded alias' => true]);

test('an authoritative source loaded inside a parameter handler fails instead of reusing caller input or replaying handlers', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'HandlerCurrentRequest_' . $suffix;
    $function = 'readHandlerRequest_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        function %s(
            #[LoadRequestAlias(%s, duringHandler: true), %s] \Psr\Http\Message\ServerRequestInterface $value,
        ): \Psr\Http\Message\ServerRequestInterface { return $value; }
        PHP,
        __NAMESPACE__,
        $function,
        var_export(__NAMESPACE__ . '\\' . $alias, true),
        $alias,
    ));
    LoadRequestAlias::$instances = [];
    $observer = new ObserveRequestInput();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            LoadRequestAlias::class,
            $observer,
            [ValueTransformer::class],
            before: [CurrentRequest::class],
        ))
        ->build();
    $request = new ServerRequest('GET', '/actual');
    $params = ['value' => new ServerRequest('GET', '/caller'), ServerRequestInterface::class => $request];

    expect(fn() => $container->call(__NAMESPACE__ . '\\' . $function, $params))
        ->toThrow(AttributeCompositionException::class, 'input policy')
        ->and($observer->resolvedInputs)->toBe([true])
        ->and(LoadRequestAlias::$instances)->toHaveCount(1)
        ->and(array_filter(LoadRequestAlias::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))->toBe([]);

    expect($container->call(__NAMESPACE__ . '\\' . $function, $params))->toBe($request)
        ->and($observer->resolvedInputs)->toBe([true, false]);
});

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ConstructorOrderFirst {}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ConstructorOrderLoader
{
    /** @var list<WeakReference<self>> */
    public static array $instances = [];

    public function __construct(string $alias)
    {
        self::$instances[] = WeakReference::create($this);
        if (!class_exists($alias, false)) {
            class_alias(ConstructorOrderFirst::class, $alias);
        }
    }
}

final class ConstructorOrderLog
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class ConstructorOrderHandler implements AttributeHandlerInterface
{
    public function __construct(private ConstructorOrderLog $log, private string $label) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->log->events[] = ($context->entry === null ? 'before:' : 'after:') . $this->label;
        if ($target instanceof ReflectionProperty) {
            if (!$context->propertyClaimed($target) && !$context->claimProperty($target)) {
                return;
            }
            $value = $context->readProperty($target);
            if (!is_string($value)) {
                throw new LogicException('Expected a string property to transform.');
            }
            $context->writeProperty($target, $value . ':' . $this->label);
        }
    }
}

abstract class ConstructorOrderState
{
    public string $value = 'input';
}

test('object handlers honor predecessors loaded by attribute constructors with fresh instances for each phase', function (
    string $kind,
    AttributePhase $phase,
    bool $preloaded,
): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'ConstructorPredecessor_' . $suffix;
    $target = 'ConstructorOrderTarget_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    $attributes = sprintf('#[ConstructorOrderLoader(%s), %s]', var_export($fullAlias, true), $alias);
    $declaration = match ($kind) {
        'class' => $attributes . ' final class ' . $target . ' extends ConstructorOrderState {}',
        'property' => 'final class ' . $target . ' extends ConstructorOrderState { ' . $attributes . ' public string $value = "input"; }',
        'method' => 'final class ' . $target . ' extends ConstructorOrderState { ' . $attributes . ' public function hook(): void {} }',
        default => throw new LogicException('Unknown attribute target kind.'),
    };
    eval('namespace ' . __NAMESPACE__ . '; ' . $declaration);
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, ConstructorOrderState::class, true)) {
        throw new LogicException('Expected the object attribute fixture.');
    }
    if ($preloaded) {
        class_alias(ConstructorOrderFirst::class, $fullAlias);
    }
    ConstructorOrderLoader::$instances = [];
    $log = new ConstructorOrderLog();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ConstructorOrderFirst::class,
            new ConstructorOrderHandler($log, 'first'),
            [ValueTransformer::class],
            before: [ConstructorOrderLoader::class],
            phase: $phase,
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            ConstructorOrderLoader::class,
            new ConstructorOrderHandler($log, 'second'),
            [ValueTransformer::class],
            phase: $phase,
        ))
        ->build();
    expect($container->has($class))->toBeTrue()
        ->and(ConstructorOrderLoader::$instances)->toBe([]);
    $expectedEvents = match ($phase) {
        AttributePhase::BeforeInstantiation => ['before:first', 'before:second'],
        AttributePhase::AfterInstantiation => ['after:first', 'after:second'],
        AttributePhase::Both => ['before:first', 'before:second', 'after:first', 'after:second'],
    };
    $instancesPerObject = $phase === AttributePhase::Both ? 2 : 1;

    for ($iteration = 1; $iteration <= 2; ++$iteration) {
        $log->events = [];
        $object = $container->make($class);
        if (!$object instanceof ConstructorOrderState) {
            throw new LogicException('Expected the created object.');
        }
        expect($log->events)->toBe($expectedEvents)
            ->and($object->value)->toBe($kind === 'property' ? 'input:first:second' : 'input')
            ->and(ConstructorOrderLoader::$instances)->toHaveCount($iteration * $instancesPerObject)
            ->and(array_filter(ConstructorOrderLoader::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))
            ->toBe([]);
    }
})->with([
    'class before construction' => ['class', AttributePhase::BeforeInstantiation],
    'property after construction' => ['property', AttributePhase::AfterInstantiation],
    'method in both phases' => ['method', AttributePhase::Both],
])->with(['late alias' => false, 'preloaded alias' => true]);
