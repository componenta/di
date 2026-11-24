<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Lazy\LatePropertyProxyArgumentsContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionFunction;
use Reflector;
use RuntimeException;
use Throwable;
use TypeError;
use WeakReference;

final class Trace
{
    /** @var list<string> */
    public static array $events = [];
    /** @var list<WeakReference<object>> */
    public static array $arguments = [];
    public static int $constructors = 0;
    public static int $products = 0;
}

final class Product
{
    public string $value = 'ready';

    public function __construct()
    {
        ++Trace::$products;
    }
}

abstract class Owner
{
    public bool $ready = false;
    public Product $product;

    public function __construct()
    {
        ++Trace::$constructors;
        Trace::$events[] = 'constructor';
        $this->ready = true;
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class LoadBefore
{
    public function __construct(public string $alias, public string $active = '') {}
}

#[Attribute(Attribute::TARGET_METHOD)]
final class SuffixBefore {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ActiveBefore {}

final readonly class Handler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof SuffixBefore) {
            Trace::$events[] = 'suffix';
            return;
        }
        if ($attribute instanceof ActiveBefore) {
            Trace::$events[] = 'active';
            return;
        }
        if (!$attribute instanceof LoadBefore || $context->entry !== null) {
            throw new LogicException('Expected the before-phase loader.');
        }
        Trace::$events[] = 'loader';
        if (!class_exists($attribute->alias, false)) {
            class_alias(Proxy::class, $attribute->alias);
        }
        if ($attribute->active !== '' && !class_exists($attribute->active, false)) {
            class_alias(ActiveBefore::class, $attribute->active);
        }
    }
}

final readonly class UncachedRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class StringableArgument
{
    public function __construct(string $suffix, bool $constructorPolicy = false)
    {
        Trace::$events[] = 'argument';
        Trace::$arguments[] = WeakReference::create($this);
        if ($suffix !== '' && !class_exists($suffix, false)) {
            class_alias($constructorPolicy ? NoConstructor::class : SuffixBefore::class, $suffix);
        }
    }

    public function __toString(): string
    {
        return Product::class;
    }
}

final class InvalidArgument
{
    public function __construct()
    {
        Trace::$events[] = 'argument';
    }
}

function builder(bool $uncached): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            LoadBefore::class,
            new Handler(),
            rules: $uncached ? [new UncachedRule()] : [],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->addAttributeDefinition(new AttributeDefinition(SuffixBefore::class, new Handler(), phase: AttributePhase::BeforeInstantiation))
        ->addAttributeDefinition(new AttributeDefinition(ActiveBefore::class, new Handler(), phase: AttributePhase::BeforeInstantiation));
}

/** @return array{class:class-string<Owner>,proxy:string,policy:string,holder:string} */
function fixture(
    string $strategy = 'eager',
    string $argument = 'constant',
    bool $loadSuffix = false,
    bool $make = false,
): array {
    $suffix = bin2hex(random_bytes(6));
    $class = 'Target_' . $suffix;
    $proxy = 'LateProxy_' . $suffix;
    $policy = 'LatePolicy_' . $suffix;
    $holder = 'ArgumentHolder_' . $suffix;
    $last = 'LateSuffix_' . $suffix;
    $expression = match ($argument) {
        'stringable' => 'new StringableArgument(' . var_export($loadSuffix ? __NAMESPACE__ . '\\' . $last : '', true) . ')',
        'invalid' => 'new InvalidArgument()',
        default => $holder . '::NAME',
    };
    $creation = $strategy === 'eager' ? '' : '#[\Componenta\DI\Attribute\\' . $strategy . ']';
    $source = $make ? sprintf('#[\\%s(Product::class)]', Make::class) : '';
    // Weak typing makes a valid native object argument observable even though
    // Proxy's final constructor stores only the converted class string.
    eval(sprintf(
        <<<'PHP'
        declare(strict_types=0);
        namespace %s;
        %s #[%s]
        final class %s extends Owner {
            %s #[%s(%s)] public Product $product;
            #[LoadBefore(%s)] public function load(): void {}
            #[%s] public function suffix(): void {}
        }
        PHP,
        __NAMESPACE__,
        $creation,
        $policy,
        $class,
        $source,
        $proxy,
        $expression,
        var_export(__NAMESPACE__ . '\\' . $proxy, true),
        $last,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Owner::class, true)) {
        throw new LogicException('Expected the late proxy argument fixture.');
    }
    return [
        'class' => $name,
        'proxy' => __NAMESPACE__ . '\\' . $proxy,
        'policy' => __NAMESPACE__ . '\\' . $policy,
        'holder' => __NAMESPACE__ . '\\' . $holder,
    ];
}

beforeEach(function (): void {
    Trace::$events = [];
    Trace::$arguments = [];
    Trace::$constructors = Trace::$products = 0;
});

test('late property Proxy materializes valid native arguments once per phase before construction', function (
    string $strategy,
    bool $uncached,
    bool $preloaded,
    bool $loadSuffix,
    bool $make,
): void {
    $fixture = fixture($strategy, 'stringable', $loadSuffix, $make);
    if ($preloaded) {
        class_alias(Proxy::class, $fixture['proxy']);
    }
    $container = builder($uncached)->build();
    expect($container->has($fixture['class']))->toBeTrue()->and(Trace::$events)->toBe([]);

    foreach ([1, 2] as $attempt) {
        Trace::$events = [];
        $before = $preloaded || $attempt > 1 ? ['argument', 'loader'] : ['loader', 'argument'];
        if ($loadSuffix) {
            $before[] = 'suffix';
        }
        $owner = $container->make($fixture['class']);
        Assert::assertInstanceOf(Owner::class, $owner);
        $after = [...$before, 'constructor', 'argument'];
        expect(Trace::$events)->toBe($strategy === 'eager' ? $after : $before)
            ->and($owner->ready)->toBeTrue()
            ->and(Trace::$events)->toBe($after)
            ->and(Trace::$constructors)->toBe($attempt)
            ->and(Trace::$arguments)->toHaveCount(2 * $attempt)
            ->and(array_all(Trace::$arguments, static fn(WeakReference $reference): bool => $reference->get() === null))->toBeTrue()
            ->and(Trace::$products)->toBe($attempt - 1)
            ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($owner->product))->toBeTrue()
            ->and($owner->product->value)->toBe('ready')
            ->and(Trace::$products)->toBe($attempt);
    }
})->with(['eager', 'Lazy', 'Proxy'])
    ->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['late alias' => false, 'preloaded control' => true])
    ->with(['stable argument' => false, 'argument reveals later handler' => true])
    ->with(['Proxy alone' => false, 'Make with Proxy' => true]);

test('Make and Proxy on a parameter evaluate the class argument once per invocation', function (bool $closure): void {
    $name = 'ProxyParameter_' . bin2hex(random_bytes(6));
    eval(sprintf(
        <<<'PHP'
        declare(strict_types=0);
        namespace %s;
        function %s(
            #[\Componenta\DI\Attribute\Make(Product::class), \Componenta\DI\Attribute\Proxy(new StringableArgument(''))]
            Product $product,
        ): Product {
            return $product;
        }
        PHP,
        __NAMESPACE__,
        $name,
    ));
    $name = __NAMESPACE__ . '\\' . $name;
    $target = $closure ? new ReflectionFunction($name)->getClosure() : $name;
    $container = builder(false)->build();

    foreach ([1, 2] as $attempt) {
        Trace::$events = [];
        $product = $container->call($target);
        Assert::assertInstanceOf(Product::class, $product);
        expect(Trace::$events)->toBe(['argument'])
            ->and(Trace::$arguments)->toHaveCount($attempt)
            ->and(array_all(Trace::$arguments, static fn(WeakReference $reference): bool => $reference->get() === null))->toBeTrue()
            ->and(Trace::$products)->toBe($attempt - 1)
            ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($product))->toBeTrue()
            ->and($product->value)->toBe('ready')
            ->and(Trace::$products)->toBe($attempt);
    }
})->with(['named callable' => false, 'closure' => true]);

test('constructor policies revealed by a late property Proxy argument fail before the owner constructor', function (bool $uncached, bool $preloaded): void {
    $fixture = fixture();
    if ($preloaded) {
        class_alias(Proxy::class, $fixture['proxy']);
    }
    $autoload = static function (string $class) use ($fixture): void {
        if ($class === $fixture['holder']) {
            Trace::$events[] = 'argument';
            class_alias(NoConstructor::class, $fixture['policy']);
            eval(sprintf('namespace %s; final class %s { public const NAME = Product::class; }', __NAMESPACE__, substr($class, strrpos($class, '\\') + 1)));
        }
    };
    spl_autoload_register($autoload);
    try {
        $container = builder($uncached)->build();
        expect($container->has($fixture['class']))->toBeTrue()->and(Trace::$events)->toBe([]);
        if (!$preloaded) {
            expect(fn(): mixed => $container->get($fixture['class']))
                ->toThrow(AttributeCompositionException::class, 'was already executed')
                ->and(Trace::$events)->toBe(['loader', 'argument'])
                ->and(Trace::$constructors)->toBe(0);
            Trace::$events = [];
        }

        $owner = $container->get($fixture['class']);
        expect($owner)->toBeInstanceOf(Owner::class);
        if (!$owner instanceof Owner) {
            throw new LogicException('Expected the configured owner.');
        }
        expect($owner->ready)->toBeFalse()
            ->and(Trace::$constructors)->toBe(0)
            ->and(Trace::$events)->toBe($preloaded ? ['argument', 'loader'] : ['loader'])
            ->and($container->get($fixture['class']))->toBe($owner)
            ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($owner->product))->toBeTrue();
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['late alias' => false, 'preloaded control' => true]);

test('late property Proxy argument failures keep property diagnostics and stop construction', function (string $kind): void {
    $fixture = fixture(argument: $kind === 'native type' ? 'invalid' : 'constant');
    $cause = $kind === 'owned' ? new ResolutionException('owned argument failure') : new RuntimeException('argument autoload failure');
    $autoload = static function (string $class) use ($fixture, $cause): void {
        if ($class === $fixture['holder']) {
            Trace::$events[] = 'argument';
            throw $cause;
        }
    };
    spl_autoload_register($autoload);
    try {
        $container = builder(false)->build();
        $failure = null;
        try {
            $container->get($fixture['class']);
        } catch (Throwable $error) {
            $failure = $error;
        }
        expect($failure)->toBeInstanceOf(ResolutionException::class);
        if (!$failure instanceof ResolutionException) {
            throw new LogicException('Expected a normalized property argument failure.');
        }
        if ($kind === 'owned') {
            expect($failure)->toBe($cause);
        } else {
            expect($failure->propertyClass)->toBe($fixture['class'])
                ->and($failure->propertyName)->toBe('product');
            if ($kind === 'native type') {
                expect($failure->getPrevious())->toBeInstanceOf(TypeError::class);
            } else {
                expect($failure->getPrevious())->toBe($cause);
            }
        }
        expect(Trace::$events)->toBe(['loader', 'argument'])
            ->and(Trace::$constructors)->toBe(0)
            ->and(Trace::$products)->toBe(0);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['native type', 'foreign', 'owned']);

test('invalid active predecessors stop native arguments of skipped property proxies', function (bool $discoveredByArgument, bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $class = 'PredecessorTarget_' . $suffix;
    $proxy = 'PredecessorProxy_' . $suffix;
    $policy = 'PredecessorPolicy_' . $suffix;
    $active = 'ActivePredecessor_' . $suffix;
    $policyAlias = __NAMESPACE__ . '\\' . $policy;
    $activeAlias = __NAMESPACE__ . '\\' . $active;
    $properties = $discoveredByArgument
        ? sprintf('#[%s(new StringableArgument(%s, true))] public Product $product; #[%s(new InvalidArgument())] public Product $second;', $proxy, var_export($policyAlias, true), $proxy)
        : sprintf('#[%s(new InvalidArgument())] public Product $product; #[%s] public string $second = "unused";', $proxy, $active);
    eval(sprintf(
        'declare(strict_types=0); namespace %s; #[%s] final class %s extends Owner { %s #[LoadBefore(%s, %s)] public function load(): void {} }',
        __NAMESPACE__,
        $policy,
        $class,
        $properties,
        var_export(__NAMESPACE__ . '\\' . $proxy, true),
        var_export($discoveredByArgument ? '' : $activeAlias, true),
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Owner::class, true)) {
        throw new LogicException('Expected the invalid predecessor fixture.');
    }
    $container = builder($uncached)->build();

    expect(fn(): object => $container->make($name))
        ->toThrow(AttributeCompositionException::class, 'was already executed')
        ->and(Trace::$events)->toBe($discoveredByArgument ? ['loader', 'argument'] : ['loader'])
        ->and(Trace::$constructors)->toBe(0)
        ->and(Trace::$products)->toBe(0)
        ->and(array_all(Trace::$arguments, static fn(WeakReference $reference): bool => $reference->get() === null))->toBeTrue();
})->with(['known invalid prefix' => false, 'first proxy argument reveals invalid prefix' => true])
    ->with(['cached plans' => false, 'uncached plans' => true]);
