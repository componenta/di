<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectAttributeCacheParity;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;
use RuntimeException;
use stdClass;
use WeakReference;

#[Attribute(Attribute::TARGET_ALL)]
final class UnusedUncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function parityBuilder(bool $uncached): ContainerBuilder
{
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UnusedUncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

final class NativeArgument
{
    public static int $current = 0;
    /** @var list<WeakReference<self>> */
    public static array $instances = [];
    public readonly int $value;

    public function __construct()
    {
        $this->value = self::$current;
        self::$instances[] = WeakReference::create($this);
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ObserveNativeArgument
{
    /** @var list<WeakReference<self>> */
    public static array $instances = [];

    public function __construct(public readonly string $scope, public readonly NativeArgument $argument)
    {
        self::$instances[] = WeakReference::create($this);
    }
}

abstract class ParentFixture
{
    /** @var list<array{string,string,string,int}> */
    public array $events = [];

    #[ObserveNativeArgument(self::class, new NativeArgument())]
    private string $sameName = 'parent';

    #[ObserveNativeArgument(self::class, new NativeArgument())]
    private function sameMethod(): void {}

    public function parentState(): string
    {
        $this->sameMethod();
        return $this->sameName;
    }
}

trait FixtureTrait
{
    #[ObserveNativeArgument(self::class, new NativeArgument())]
    public string $traitProperty = 'trait';

    #[ObserveNativeArgument(self::class, new NativeArgument())]
    public function traitMethod(): void {}
}

#[ObserveNativeArgument(self::class, new NativeArgument())]
final class FirstFixture extends ParentFixture
{
    use FixtureTrait {
        traitMethod as aliasedTraitMethod;
    }

    #[ObserveNativeArgument(self::class, new NativeArgument())]
    private string $sameName = 'child';

    #[ObserveNativeArgument(self::class, new NativeArgument())]
    private function sameMethod(): void {}

    public function childState(): string
    {
        $this->sameMethod();
        return $this->sameName;
    }
}

#[ObserveNativeArgument(self::class, new NativeArgument())]
final class SecondFixture extends ParentFixture
{
    use FixtureTrait;
}

final class ObservationHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof ObserveNativeArgument || !$context->entry instanceof ParentFixture) {
            throw new LogicException('Expected the native argument observation fixtures.');
        }
        $owner = match (true) {
            $target instanceof ReflectionClass => $target->getName(),
            $target instanceof ReflectionProperty, $target instanceof ReflectionMethod => $target->getDeclaringClass()->getName(),
            default => throw new LogicException('Expected an object attribute target.'),
        };
        $context->entry->events[] = [$attribute->scope, $owner, $target->getName(), $attribute->argument->value];
    }
}

test('cached and uncached object plans preserve native arguments across traits inherited members and aliases', function (): void {
    $alias = __NAMESPACE__ . '\\FirstFixtureAlias_' . bin2hex(random_bytes(5));
    class_alias(FirstFixture::class, $alias);
    $classes = [FirstFixture::class, SecondFixture::class, $alias, FirstFixture::class];
    $results = [];
    try {
        foreach ([false, true] as $uncached) {
            $container = parityBuilder($uncached)
                ->addAttributeDefinition(new AttributeDefinition(ObserveNativeArgument::class, new ObservationHandler()))
                ->build();
            NativeArgument::$instances = [];
            ObserveNativeArgument::$instances = [];
            $expectedInstances = 0;
            foreach ($classes as $class) {
                expect($container->has($class))->toBeTrue();
            }
            expect(NativeArgument::$instances)->toBe([])
                ->and(ObserveNativeArgument::$instances)->toBe([]);

            foreach ($classes as $index => $class) {
                if (!is_a($class, ParentFixture::class, true)) {
                    throw new LogicException('Expected the object parity fixture class.');
                }
                NativeArgument::$current = $index + 1;
                $entry = $container->make($class);
                if (!$entry instanceof ParentFixture) {
                    throw new LogicException('Expected the created object parity fixture.');
                }
                expect($entry->parentState())->toBe('parent');
                if ($entry instanceof FirstFixture) {
                    expect($entry->childState())->toBe('child');
                }
                foreach ($entry->events as [$scope, $owner, $_member, $value]) {
                    expect($scope)->toBe($owner)->and($value)->toBe($index + 1);
                }
                $results[(int) $uncached][] = $entry->events;
                $expectedInstances += count($entry->events);
                $objectReference = WeakReference::create($entry);
                unset($entry);
                gc_collect_cycles();
                expect($objectReference->get())->toBeNull()
                    ->and(NativeArgument::$instances)->toHaveCount($expectedInstances)
                    ->and(ObserveNativeArgument::$instances)->toHaveCount($expectedInstances)
                    ->and(array_filter(NativeArgument::$instances, static fn(WeakReference $reference): bool => $reference->get() !== null))->toBe([])
                    ->and(array_filter(ObserveNativeArgument::$instances, static fn(WeakReference $reference): bool => $reference->get() !== null))->toBe([]);
            }
        }
        expect($results[0])->toBe($results[1])
            ->and(array_column($results[0][0], 2))->toContain('traitMethod', 'aliasedTraitMethod')
            ->and(array_column($results[0][0], 1))->toContain(ParentFixture::class, FirstFixture::class)
            ->and(array_column($results[0][1], 1))->toContain(ParentFixture::class, SecondFixture::class);
    } finally {
        NativeArgument::$instances = [];
        ObserveNativeArgument::$instances = [];
        NativeArgument::$current = 0;
    }
});

abstract class DeferredFixture
{
    public static int $failuresRemaining = 0;
    public static int $constructions = 0;
    public int $value;

    public function __construct(int $value)
    {
        ++self::$constructions;
        if (self::$failuresRemaining > 0) {
            --self::$failuresRemaining;
            throw new RuntimeException('Retry the deferred constructor.');
        }
        $this->value = $value;
    }
}

#[Lazy]
final class GhostFixture extends DeferredFixture {}

#[Proxy]
final class ProxyFixture extends DeferredFixture {}

test('cached and uncached deferred plans release unused runtime closures and payloads', function (bool $uncached, string $class, string $mode): void {
    if (!is_a($class, DeferredFixture::class, true)) {
        throw new LogicException('Expected the deferred parity fixture class.');
    }
    $container = parityBuilder($uncached)->build();
    expect($container->has($class))->toBeTrue();
    DeferredFixture::$constructions = 0;
    DeferredFixture::$failuresRemaining = $mode === 'retry' ? 1 : 0;
    $payload = new stdClass();
    $callback = static fn(): object => $payload;
    $payloadReference = WeakReference::create($payload);
    $callbackReference = WeakReference::create($callback);
    $entry = $container->make($class, ['value' => 37, 'unused' => $callback]);
    unset($payload, $callback);
    expect(DeferredFixture::$constructions)->toBe(0)
        ->and($payloadReference->get())->not->toBeNull()
        ->and($callbackReference->get())->not->toBeNull();

    if ($mode === 'retry') {
        expect(fn(): int => $entry->value)->toThrow(ResolutionException::class, 'Retry the deferred constructor.')
            ->and(DeferredFixture::$constructions)->toBe(1)
            ->and($payloadReference->get())->not->toBeNull()
            ->and($callbackReference->get())->not->toBeNull();
    }
    if ($mode !== 'discard') {
        expect($entry->value)->toBe(37)
            ->and(DeferredFixture::$constructions)->toBe($mode === 'retry' ? 2 : 1);
        gc_collect_cycles();
        expect($payloadReference->get())->toBeNull()
            ->and($callbackReference->get())->toBeNull();
    }
    unset($entry);
    gc_collect_cycles();
    expect($payloadReference->get())->toBeNull()
        ->and($callbackReference->get())->toBeNull()
        ->and(DeferredFixture::$constructions)->toBe(match ($mode) {
            'discard' => 0,
            'retry' => 2,
            default => 1,
        });
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with([GhostFixture::class, ProxyFixture::class])
    ->with(['initialize', 'retry', 'discard']);

final class ReadinessDependency {}

final class ReadinessState
{
    public bool $ready = false;
}

abstract class ReadinessFixture
{
    public int $initializations = 0;

    public function __construct(public readonly ReadinessDependency $dependency) {}

    public function initialize(): void
    {
        ++$this->initializations;
    }
}

test('cached and uncached object plans notice an existing autoloader becoming ready inside a dependency', function (bool $uncached, bool $warm): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'ReadySetUp_' . $suffix;
    $target = 'ReadyTarget_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s("initialize")] final class %s extends ReadinessFixture {}',
        __NAMESPACE__,
        $attribute,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, ReadinessFixture::class, true)) {
        throw new LogicException('Expected the autoloader readiness fixture.');
    }
    $state = new ReadinessState();
    $loads = 0;
    $loader = static function (string $requested) use ($attribute, $state, &$loads): void {
        if ($state->ready && $requested === __NAMESPACE__ . '\\' . $attribute) {
            ++$loads;
            class_alias(SetUp::class, $requested);
        }
    };
    $dependencyCreations = 0;
    $container = parityBuilder($uncached)
        ->addFactory(ReadinessDependency::class, static function () use ($state, &$dependencyCreations): ReadinessDependency {
            ++$dependencyCreations;
            $state->ready = true;
            return new ReadinessDependency();
        })
        ->build();
    spl_autoload_register($loader);
    try {
        if ($warm) {
            expect($container->has($class))->toBeTrue()
                ->and($state->ready)->toBeFalse();
        }
        foreach ([1, 2] as $_attempt) {
            $entry = $container->make($class);
            if (!$entry instanceof ReadinessFixture) {
                throw new LogicException('Expected the initialized readiness fixture.');
            }
            expect($entry->initializations)->toBe(1);
        }
        expect($dependencyCreations)->toBe(1)->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);

final class ClosureDependency
{
    public int $value = 0;
}

abstract class NativeClosureFixture
{
    public int $value;
}

test('cached and uncached native closure attribute arguments keep fresh closure state', function (bool $uncached): void {
    $target = 'NativeClosureTarget_' . bin2hex(random_bytes(5));
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends NativeClosureFixture {
            #[\Componenta\DI\Attribute\Init(static function (ClosureDependency $dependency): int {
                static $invocations = 0;
                return $dependency->value + ++$invocations;
            })]
            public int $value;
        }
        PHP,
        __NAMESPACE__,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, NativeClosureFixture::class, true)) {
        throw new LogicException('Expected the native closure fixture.');
    }
    $dependency = new ClosureDependency();
    $container = parityBuilder($uncached)->addService(ClosureDependency::class, $dependency)->build();
    expect($container->has($class))->toBeTrue();
    foreach ([10, 20, 30] as $value) {
        $dependency->value = $value;
        $entry = $container->make($class);
        if (!$entry instanceof NativeClosureFixture) {
            throw new LogicException('Expected the initialized native closure fixture.');
        }
        expect($entry->value)->toBe($value + 1);
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->skip(PHP_VERSION_ID < 80500, 'Native closure attribute arguments require PHP 8.5.');
