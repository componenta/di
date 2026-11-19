<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BeforePhasePropertyProxy;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use Reflector;
use WeakReference;

final class Trace
{
    /** @var list<string> */
    public static array $events = [];
    public static int $products = 0;
    public static int $attributes = 0;
    /** @var list<WeakReference<PendingBefore>> */
    public static array $pending = [];
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final readonly class LoadBefore
{
    public function __construct(public string $alias)
    {
        ++Trace::$attributes;
    }
}

final class Product
{
    public string $value;

    public function __construct()
    {
        ++Trace::$products;
        $this->value = 'initialized';
    }
}

abstract class Owner
{
    public bool $ready = false;
    public Product $product;

    public function __construct()
    {
        Trace::$events[] = 'constructor';
        $this->ready = true;
    }

    public function setup(): void
    {
        Trace::$events[] = 'setup';
    }
}

final readonly class BeforeHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($context->entry !== null) {
            throw new LogicException('The fixture handler must run before instantiation.');
        }
        if ($attribute instanceof Proxy) {
            Trace::$events[] = 'custom proxy';
            return;
        }
        if (!$attribute instanceof LoadBefore) {
            throw new LogicException('Expected the before-phase loader.');
        }
        Trace::$events[] = 'loader';
        if (!class_exists($attribute->alias, false)) {
            class_alias(Proxy::class, $attribute->alias);
        }
    }
}

final readonly class UncachedRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

/** @return array{class:class-string<Owner>,alias:string} */
function fixture(string $strategy, string $loader, bool $classProxy = false): array
{
    $suffix = bin2hex(random_bytes(6));
    $class = 'Owner_' . $suffix;
    $alias = 'LateProxy_' . $suffix;
    $policy = $strategy === 'eager' ? '' : '#[\\Componenta\\DI\\Attribute\\' . $strategy . ']';
    $load = '#[LoadBefore(' . var_export(__NAMESPACE__ . '\\' . $alias, true) . ')]';
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s %s #[\Componenta\DI\Attribute\SetUp('setup')]
        final class %s extends Owner
        {
            %s public Product $product {
                set {
                    Trace::$events[] = 'property';
                    $this->product = $value;
                }
            }
            %s public string $marker = 'unused';
            %s public function load(): void {}
        }
        PHP,
        __NAMESPACE__,
        $policy,
        $classProxy ? '#[' . $alias . ']' : '',
        $class,
        $classProxy ? '' : '#[\\Componenta\\DI\\Attribute\\Make(Product::class), ' . $alias . ']',
        $loader === 'property' ? $load : '',
        $loader === 'method' ? $load : '',
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Owner::class, true)) {
        throw new LogicException('Expected the generated owner fixture.');
    }
    return ['class' => $name, 'alias' => __NAMESPACE__ . '\\' . $alias];
}

beforeEach(function (): void {
    Trace::$events = [];
    Trace::$products = 0;
    Trace::$attributes = 0;
    Trace::$pending = [];
});

test('property Proxy discovered by a later before handler applies in its pending injection phase', function (
    string $strategy,
    string $loader,
    bool $uncached,
    bool $preloaded,
    bool $warm,
): void {
    $fixture = fixture($strategy, $loader);
    if ($preloaded) {
        class_alias(Proxy::class, $fixture['alias']);
    }
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            LoadBefore::class,
            new BeforeHandler(),
            rules: $uncached ? [new UncachedRule()] : [],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->build();
    if ($warm) {
        expect($container->has($fixture['class']))->toBeTrue()
            ->and(Trace::$events)->toBe([])
            ->and(Trace::$attributes)->toBe(0);
    }

    foreach ([1, 2] as $attempt) {
        Trace::$events = [];
        $owner = $container->make($fixture['class']);
        expect(Trace::$events)->toBe($strategy === 'eager' ? ['loader', 'constructor', 'property', 'setup'] : ['loader']);
        $product = $owner->product;
        expect(Trace::$events)->toBe(['loader', 'constructor', 'property', 'setup'])
            ->and(Trace::$attributes)->toBe($attempt)
            ->and(Trace::$products)->toBe($attempt - 1)
            ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($product))->toBeTrue()
            ->and($product->value)->toBe('initialized')
            ->and($product->value)->toBe('initialized')
            ->and(Trace::$products)->toBe($attempt);
    }
})->with(['eager', 'Lazy', 'Proxy'])
    ->with(['property', 'method'])
    ->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['late alias' => false, 'preloaded control' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);

test('a late Proxy retains before-phase ordering when it owns a class strategy or a custom property handler', function (
    bool $classProxy,
    bool $preloaded,
): void {
    $fixture = fixture('eager', 'method', $classProxy);
    if ($preloaded) {
        class_alias(Proxy::class, $fixture['alias']);
    }
    $handler = new BeforeHandler();
    $builder = new ContainerBuilder();
    if (!$classProxy) {
        $builder->replaceAttributeDefinitions()->addAttributeDefinition(new AttributeDefinition(
            Proxy::class,
            $handler,
            phase: AttributePhase::BeforeInstantiation,
        ));
    }
    $container = $builder->addAttributeDefinition(new AttributeDefinition(
        LoadBefore::class,
        $handler,
        phase: AttributePhase::BeforeInstantiation,
    ))->build();

    if (!$preloaded) {
        expect(fn(): object => $container->make($fixture['class']))
            ->toThrow(AttributeCompositionException::class, 'was already executed')
            ->and(Trace::$events)->toBe(['loader'])
            ->and(Trace::$products)->toBe(0);
        return;
    }

    $owner = $container->make($fixture['class']);
    expect($owner->ready)->toBeTrue()
        ->and(Trace::$events)->toBe($classProxy
            ? ['loader', 'constructor', 'setup']
            : ['custom proxy', 'loader', 'constructor']);
})->with(['class strategy' => true, 'custom property handler' => false])
    ->with(['late alias' => false, 'preloaded control' => true]);

test('ordinary property Proxy arguments retain their before-constructor materialization', function (string $strategy): void {
    $suffix = bin2hex(random_bytes(6));
    $holder = 'ProxyClass_' . $suffix;
    $class = 'ArgumentOwner_' . $suffix;
    $policy = $strategy === 'eager' ? '' : '#[\\Componenta\\DI\\Attribute\\' . $strategy . ']';
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s #[\Componenta\DI\Attribute\SetUp('setup')]
        final class %s extends Owner
        {
            #[\Componenta\DI\Attribute\Make(Product::class), \Componenta\DI\Attribute\Proxy(%s::NAME)]
            public Product $product;
        }
        PHP,
        __NAMESPACE__,
        $policy,
        $class,
        $holder,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Owner::class, true)) {
        throw new LogicException('Expected the proxy argument owner.');
    }
    $autoload = static function (string $requested) use ($holder): void {
        if ($requested === __NAMESPACE__ . '\\' . $holder) {
            Trace::$events[] = 'proxy argument';
            eval(sprintf('namespace %s; final class %s { public const string NAME = Product::class; }', __NAMESPACE__, $holder));
        }
    };
    spl_autoload_register($autoload);
    try {
        $container = (new ContainerBuilder())->build();
        expect($container->has($name))->toBeTrue()->and(Trace::$events)->toBe([]);
        $owner = $container->make($name);
        expect(Trace::$events)->toBe($strategy === 'eager' ? ['proxy argument', 'constructor', 'setup'] : ['proxy argument'])
            ->and($owner->ready)->toBeTrue()
            ->and(Trace::$events)->toBe(['proxy argument', 'constructor', 'setup'])
            ->and(Trace::$products)->toBe(0)
            ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($owner->product))->toBeTrue();
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['eager', 'Lazy', 'Proxy']);

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class FirstBefore {}

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SuffixBefore {}

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PendingBefore
{
    public function __construct(string $proxyAlias, public string $suffixAlias)
    {
        Trace::$pending[] = WeakReference::create($this);
        if (!class_exists($proxyAlias, false)) {
            class_alias(Proxy::class, $proxyAlias);
        }
    }
}

final readonly class RefreshHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof FirstBefore) {
            Trace::$events[] = 'first';
        } elseif ($attribute instanceof PendingBefore) {
            Trace::$events[] = 'pending';
            if (!class_exists($attribute->suffixAlias, false)) {
                class_alias(SuffixBefore::class, $attribute->suffixAlias);
            }
        } elseif ($attribute instanceof SuffixBefore) {
            Trace::$events[] = 'suffix';
        } else {
            throw new LogicException('Expected a repeated-refresh attribute.');
        }
    }
}

test('pending attributes survive repeated refreshes without restoring an inactive predecessor or replaying handlers', function (
    string $strategy,
    bool $uncached,
): void {
    $suffix = bin2hex(random_bytes(6));
    $class = 'RefreshOwner_' . $suffix;
    $proxy = 'RefreshProxy_' . $suffix;
    $after = 'RefreshSuffix_' . $suffix;
    $policy = $strategy === 'eager' ? '' : '#[\\Componenta\\DI\\Attribute\\' . $strategy . ']';
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s #[\Componenta\DI\Attribute\SetUp('setup')]
        final class %s extends Owner
        {
            #[\Componenta\DI\Attribute\Make(Product::class), %s]
            public Product $product;
            #[FirstBefore] public function first(): void {}
            #[PendingBefore(%s, %s)] public function pending(): void {}
            #[%s] public function suffix(): void {}
        }
        PHP,
        __NAMESPACE__,
        $policy,
        $class,
        $proxy,
        var_export(__NAMESPACE__ . '\\' . $proxy, true),
        var_export(__NAMESPACE__ . '\\' . $after, true),
        $after,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Owner::class, true)) {
        throw new LogicException('Expected the repeated-refresh owner.');
    }
    $handler = new RefreshHandler();
    $builder = new ContainerBuilder();
    foreach ([FirstBefore::class, PendingBefore::class, SuffixBefore::class] as $attribute) {
        $builder->addAttributeDefinition(new AttributeDefinition(
            $attribute,
            $handler,
            rules: $uncached ? [new UncachedRule()] : [],
            phase: AttributePhase::BeforeInstantiation,
        ));
    }
    $container = $builder->build();
    expect($container->has($name))->toBeTrue()->and(Trace::$pending)->toBe([]);

    foreach ([1, 2] as $attempt) {
        Trace::$events = [];
        $owner = $container->make($name);
        expect($owner->ready)->toBeTrue()
            ->and(Trace::$events)->toBe(['first', 'pending', 'suffix', 'constructor', 'setup'])
            ->and(Trace::$pending)->toHaveCount($attempt)
            ->and(array_all(Trace::$pending, static fn(WeakReference $reference): bool => $reference->get() === null))->toBeTrue()
            ->and(Trace::$products)->toBe(0)
            ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($owner->product))->toBeTrue();
    }
})->with(['eager', 'Lazy', 'Proxy'])->with(['cached plans' => false, 'uncached plans' => true]);


abstract class PairOwner extends Owner
{
    public Product $second;
}

test('multiple property Proxy discoveries preserve the active before-phase prefix', function (
    bool $uncached,
    bool $preloaded,
): void {
    $suffix = bin2hex(random_bytes(6));
    $class = 'PairOwner_' . $suffix;
    $proxy = 'PairProxy_' . $suffix;
    $alias = __NAMESPACE__ . '\\' . $proxy;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[\Componenta\DI\Attribute\SetUp('setup')]
        final class %s extends PairOwner
        {
            #[\Componenta\DI\Attribute\Make(Product::class), %s]
            public Product $product;
            #[\Componenta\DI\Attribute\Make(Product::class), %s]
            public Product $second;
            #[LoadBefore(%s)] public function load(): void {}
        }
        PHP,
        __NAMESPACE__,
        $class,
        $proxy,
        $proxy,
        var_export($alias, true),
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, PairOwner::class, true)) {
        throw new LogicException('Expected the owner with two deferred products.');
    }
    if ($preloaded) {
        class_alias(Proxy::class, $alias);
    }
    $container = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        LoadBefore::class,
        new BeforeHandler(),
        rules: $uncached ? [new UncachedRule()] : [],
        phase: AttributePhase::BeforeInstantiation,
    ))->build();
    expect($container->has($name))->toBeTrue()->and(Trace::$events)->toBe([]);

    $owner = $container->make($name);
    $reflection = new ReflectionClass(Product::class);
    expect($owner->ready)->toBeTrue()
        ->and(Trace::$events)->toBe(['loader', 'constructor', 'setup'])
        ->and(Trace::$attributes)->toBe(1)
        ->and(Trace::$products)->toBe(0)
        ->and($reflection->isUninitializedLazyObject($owner->product))->toBeTrue()
        ->and($reflection->isUninitializedLazyObject($owner->second))->toBeTrue()
        ->and($owner->product)->not->toBe($owner->second)
        ->and($owner->product->value)->toBe('initialized')
        ->and(Trace::$products)->toBe(1)
        ->and($owner->second->value)->toBe('initialized')
        ->and(Trace::$products)->toBe(2);
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['late aliases' => false, 'preloaded control' => true]);

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class LoadAfter
{
    public function __construct(public string $alias)
    {
        ++Trace::$attributes;
    }
}

final readonly class AfterHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof LoadAfter || !$context->entry instanceof Owner) {
            throw new LogicException('Expected the after-phase loader on an existing owner.');
        }
        Trace::$events[] = 'after loader';
        if (!class_exists($attribute->alias, false)) {
            class_alias(Proxy::class, $attribute->alias);
        }
    }
}

test('property Proxy discovered after completed injection is rejected before sharing', function (
    bool $uncached,
    bool $preloaded,
): void {
    $suffix = bin2hex(random_bytes(6));
    $class = 'AfterOwner_' . $suffix;
    $proxy = 'AfterProxy_' . $suffix;
    $alias = __NAMESPACE__ . '\\' . $proxy;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[\Componenta\DI\Attribute\SetUp('setup')]
        final class %s extends Owner
        {
            #[\Componenta\DI\Attribute\Make(Product::class), %s]
            public Product $product {
                set {
                    Trace::$events[] = 'property';
                    $this->product = $value;
                }
            }
            #[LoadAfter(%s)] public function load(): void {}
        }
        PHP,
        __NAMESPACE__,
        $class,
        $proxy,
        var_export($alias, true),
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Owner::class, true)) {
        throw new LogicException('Expected the after-phase owner.');
    }
    if ($preloaded) {
        class_alias(Proxy::class, $alias);
    }
    $container = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        LoadAfter::class,
        new AfterHandler(),
        rules: $uncached ? [new UncachedRule()] : [],
        phase: AttributePhase::AfterInstantiation,
    ))->build();
    $events = ['constructor', 'property', 'setup', 'after loader'];

    if (!$preloaded) {
        expect(fn(): mixed => $container->get($name))->toThrow(AttributeCompositionException::class, 'was already executed')
            ->and(Trace::$events)->toBe($events)
            ->and(Trace::$products)->toBe(1);
        Trace::$events = [];
    }

    $owner = $container->get($name);
    expect($owner)->toBeInstanceOf(Owner::class);
    if (!$owner instanceof Owner) {
        throw new LogicException('Expected the successfully configured owner.');
    }
    expect(Trace::$events)->toBe($events)
        ->and(Trace::$attributes)->toBe($preloaded ? 1 : 2)
        ->and(Trace::$products)->toBe($preloaded ? 0 : 1)
        ->and(new ReflectionClass(Product::class)->isUninitializedLazyObject($owner->product))->toBeTrue()
        ->and($container->get($name))->toBe($owner)
        ->and($owner->product->value)->toBe('initialized')
        ->and(Trace::$products)->toBe($preloaded ? 1 : 2);
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['late alias' => false, 'preloaded control' => true]);
