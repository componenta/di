<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectContextSource;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Internal\Resolver\Entry\ConstructionParameters;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryParametersFactoryInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ServerRequestInterface;
use Reflector;
use WeakReference;

final class Observation
{
    /** @var list<array{string,mixed}> */
    public static array $values = [];
    /** @var list<WeakReference<ObjectCreationContext>> */
    public static array $contexts = [];
    public static int $constructors = 0;
    public static int $invoked = 0;
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ReadParameters
{
    public function __construct(
        public string $label,
        string $source = '',
        string $reveal = 'unavailable',
    ) {
        if ($source !== '' && $reveal !== 'unavailable' && !class_exists($source, false)) {
            class_alias($reveal === 'source' ? EntryId::class : Metadata::class, $source);
        }
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Metadata
{
    public function __construct(public string $value) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Reader implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof ReadParameters) {
            throw new LogicException('Expected the context reader attribute.');
        }
        Observation::$contexts[] = WeakReference::create($context);
        $parameters = $context->parameters;
        Observation::$values[] = [$attribute->label, $parameters['role'] ?? 'absent'];
        if ($context->entry instanceof Product) {
            $context->entry->state = 'ready';
        }
    }
}

abstract class Product
{
    public string $state = 'pending';
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class DeferredMapper extends MapQueryString {}

final class DeferredFactory implements EntryParametersFactoryInterface
{
    private EntryParameters $current;
    public int $suppliers = 0;

    public function __construct(private readonly ObjectPipeline $pipeline) {}

    public function make(string $entry, array $params = []): object
    {
        return $this->makeEntry($entry, ConstructionParameters::capture($params));
    }

    public function makeEntry(string $entry, EntryParameters $parameters): object
    {
        if (!class_exists($entry)) {
            throw new LogicException('Expected a declared deferred DTO.');
        }
        $this->current = $parameters;
        return $this->pipeline->create($entry, resolveParameters: function (): EntryParameters {
            ++$this->suppliers;
            return $this->current;
        });
    }
}

function builder(bool $uncached, AttributePhase $phase = AttributePhase::BeforeInstantiation): ContainerBuilder
{
    $builder = (new ContainerBuilder())
        ->addService('trusted', 'trusted')
        ->addAttributeDefinition(new AttributeDefinition(ReadParameters::class, new Reader(), phase: $phase));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

/** @return class-string<Product> */
function fixture(string $reveal, bool $priorRead = false, string $strategy = ''): string
{
    $suffix = bin2hex(random_bytes(5));
    $source = 'Source_' . $suffix;
    $class = 'Product_' . $suffix;
    $early = $priorRead ? '#[ReadParameters("early")]' : '';
    $policy = $strategy === '' ? '' : '#[\Componenta\DI\Attribute\NoConstructor, \Componenta\DI\Attribute\\' . $strategy . ']';
    eval(sprintf(
        'namespace %s; %s %s #[ReadParameters("late", %s, %s)] final class %s extends Product { public function __construct(#[%s("trusted")] string $role) { ++Observation::$constructors; } }',
        __NAMESPACE__,
        $policy,
        $early,
        var_export(__NAMESPACE__ . '\\' . $source, true),
        var_export($reveal, true),
        $class,
        $source,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Product::class, true)) {
        throw new LogicException('Expected the generated product class.');
    }
    return $name;
}

/** @param class-string<Product> $class */
function target(string $class, bool $deferred = false): Closure
{
    $mapper = $deferred ? DeferredMapper::class : MapQueryString::class;
    $target = eval(sprintf(
        'namespace %s; return static function (#[\%s] \%s $product): object { ++Observation::$invoked; return $product; };',
        __NAMESPACE__,
        $mapper,
        $class,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected a mapped public callable.');
    }
    return $target;
}

beforeEach(function (): void {
    Observation::$values = [];
    Observation::$contexts = [];
    Observation::$constructors = Observation::$invoked = 0;
});

test('object context reads reject newly revealed mapped sources and allow unrelated metadata', function (string $reveal, bool $uncached): void {
    $container = builder($uncached)->build();
    $callable = target(fixture($reveal));
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    if ($reveal === 'source') {
        expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
            ->toThrow(RequestParameterSourceConflictException::class, 'role')
            ->and(Observation::$values)->toBe([])
            ->and(Observation::$constructors)->toBe(0)
            ->and(Observation::$invoked)->toBe(0);
    } else {
        $container->call($callable, [ServerRequestInterface::class => $request]);
        expect(Observation::$values)->toBe([['late', 'untrusted']])
            ->and(Observation::$constructors)->toBe(1)
            ->and(Observation::$invoked)->toBe(1);
    }
    gc_collect_cycles();
    foreach (Observation::$contexts as $context) {
        expect($context->get())->toBeNull();
    }
})->with(['source', 'metadata', 'unavailable'])
    ->with(['cached' => false, 'non-memoized' => true]);

test('a successful earlier read does not authorize later access after a source becomes available', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $callable = target(fixture('source', priorRead: true));
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(Observation::$values)->toBe([['early', 'untrusted']])
        ->and(Observation::$constructors)->toBe(0)
        ->and(Observation::$invoked)->toBe(0);
})->with(['cached' => false, 'non-memoized' => true]);

test('handlers discovered after the handlerless constructor path receive guarded context reads', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(5));
    $source = 'FastSource_' . $suffix;
    $reader = 'FastReader_' . $suffix;
    $class = 'FastProduct_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s("late", %s, "source")] final class %s extends Product { public function __construct(#[%s("trusted")] string $role) { ++Observation::$constructors; class_alias(ReadParameters::class, %s); } }',
        __NAMESPACE__,
        $reader,
        var_export(__NAMESPACE__ . '\\' . $source, true),
        $class,
        $source,
        var_export(__NAMESPACE__ . '\\' . $reader, true),
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Product::class, true)) {
        throw new LogicException('Expected the handlerless constructor fixture.');
    }
    $container = builder($uncached, AttributePhase::AfterInstantiation)->build();
    $callable = target($name);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(Observation::$values)->toBe([])
        ->and(Observation::$constructors)->toBe(1)
        ->and(Observation::$invoked)->toBe(0);
})->with(['cached' => false, 'non-memoized' => true]);

test('lazy retries guard the current attempt parameters and release failed object contexts', function (string $strategy, bool $uncached): void {
    $pipeline = builder($uncached, AttributePhase::AfterInstantiation)->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
    $factory = new DeferredFactory($pipeline);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            DeferredMapper::class,
            new RequestAttributeHandler($factory, new TestCasterProvider()),
            [ValueProvider::class],
        ))
        ->build();
    $callable = target(fixture('source', priorRead: true, strategy: $strategy), deferred: true);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);
    $product = $container->call($callable, [ServerRequestInterface::class => $request]);
    if (!$product instanceof Product) {
        throw new LogicException('Expected the deferred product.');
    }
    expect($factory->suppliers)->toBe(0)
        ->and(Observation::$values)->toBe([]);

    expect(fn(): string => $product->state)->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(Observation::$values)->toBe([['early', 'untrusted']])
        ->and($factory->suppliers)->toBe(1)
        ->and(Observation::$constructors)->toBe(0);
    gc_collect_cycles();
    foreach (Observation::$contexts as $context) {
        expect($context->get())->toBeNull();
    }

    // A second public mapping supplies fresh provenance to the original
    // initializer's deferred provider, without forging internal metadata.
    $container->call($callable, [ServerRequestInterface::class => $request->withQueryParams([])]);
    expect($product->state)->toBe('ready')
        ->and(Observation::$values)->toBe([
            ['early', 'untrusted'],
            ['early', 'absent'],
            ['late', 'absent'],
        ])
        ->and($factory->suppliers)->toBe(2)
        ->and(Observation::$constructors)->toBe(0);
    gc_collect_cycles();
    foreach (Observation::$contexts as $context) {
        expect($context->get())->toBeNull();
    }
})->with(['Lazy', 'Proxy'])
    ->with(['cached' => false, 'non-memoized' => true]);

test('object policies that do not read context parameters preserve deferred suppliers', function (string $strategy): void {
    $class = 'Unread_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; #[\Componenta\DI\Attribute\%s] final class %s extends Product { public function __construct(#[UnreadSource] string $role) { $this->state = $role; } }',
        __NAMESPACE__,
        $strategy,
        $class,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Product::class, true)) {
        throw new LogicException('Expected the unread policy fixture.');
    }
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
    $suppliers = 0;
    $product = $pipeline->create($name, resolveParameters: static function () use (&$suppliers): array {
        ++$suppliers;
        return ['role' => 'resolved'];
    });
    if (!$product instanceof Product) {
        throw new LogicException('Expected the policy product.');
    }
    expect($suppliers)->toBe(0)
        ->and($product->state)->toBe($strategy === 'NoConstructor' ? 'pending' : 'resolved')
        ->and($suppliers)->toBe($strategy === 'NoConstructor' ? 0 : 1);
})->with(['NoConstructor', 'Lazy', 'Proxy']);

test('deferred supplier source discovery rejects mapped context before handler side effects', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(5));
    $source = __NAMESPACE__ . '\\SupplierSource_' . $suffix;
    $class = 'SupplierProduct_' . $suffix;
    eval(sprintf(
        'namespace %s; #[ReadParameters("supplied")] final class %s extends Product { public function __construct(#[\\%s("trusted")] string $role) { ++Observation::$constructors; $this->state = $role; } }',
        __NAMESPACE__,
        $class,
        $source,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_a($name, Product::class, true)) {
        throw new LogicException('Expected the supplier discovery fixture.');
    }

    $availability = new class () {
        public bool $enabled = false;
    };
    $loader = static function (string $requested) use ($source, $availability): void {
        if ($requested === $source && $availability->enabled && !class_exists($source, false)) {
            class_alias(EntryId::class, $source);
        }
    };
    spl_autoload_register($loader);
    try {
        $enableSource = static function () use ($availability): void {
            $availability->enabled = true;
        };
        $pipeline = builder($uncached)->assemble()[1]->objects;
        Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
        $factory = new class ($pipeline, $enableSource) implements EntryParametersFactoryInterface {
            public int $suppliers = 0;

            public function __construct(
                private readonly ObjectPipeline $pipeline,
                private readonly Closure $beforeSupply,
            ) {}

            public function make(string $entry, array $params = []): object
            {
                return $this->makeEntry($entry, ConstructionParameters::capture($params));
            }

            public function makeEntry(string $entry, EntryParameters $parameters): object
            {
                if (!class_exists($entry)) {
                    throw new LogicException('Expected a declared supplier discovery DTO.');
                }
                return $this->pipeline->create($entry, resolveParameters: function () use ($parameters): EntryParameters {
                    ++$this->suppliers;
                    ($this->beforeSupply)();
                    return $parameters;
                });
            }
        };
        $container = (new ContainerBuilder())
            ->addAttributeDefinition(new AttributeDefinition(
                DeferredMapper::class,
                new RequestAttributeHandler($factory, new TestCasterProvider()),
                [ValueProvider::class],
            ))
            ->build();
        $callable = target($name, deferred: true);
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

        expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
            ->toThrow(RequestParameterSourceConflictException::class, 'role')
            ->and($factory->suppliers)->toBe(1)
            ->and(Observation::$values)->toBe([])
            ->and(Observation::$constructors)->toBe(0)
            ->and(Observation::$invoked)->toBe(0);

        $product = $container->call($callable, [ServerRequestInterface::class => $request->withQueryParams([])]);
        if (!$product instanceof Product) {
            throw new LogicException('Expected the recovered supplier discovery DTO.');
        }
        expect($product->state)->toBe('trusted')
            ->and($factory->suppliers)->toBe(2)
            ->and(Observation::$values)->toBe([['supplied', 'absent']])
            ->and(Observation::$constructors)->toBe(1)
            ->and(Observation::$invoked)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached' => false, 'non-memoized' => true]);
