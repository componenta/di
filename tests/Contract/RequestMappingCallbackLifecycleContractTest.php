<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestMappingCallbackLifecycle;

use Attribute;
use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\Request\CallbackMapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use WeakReference;

#[Attribute(Attribute::TARGET_ALL)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class State
{
    /** @var list<string> */
    public static array $events = [];
    public static bool $failInitialization = false;
    public static ?Throwable $callbackFailure = null;
    public static bool $failTransientCallback = false;
    /** @var list<WeakReference<object>> */
    public static array $transient = [];
    /** @var (Closure():void)|null */
    public static ?Closure $reveal = null;
}

interface ProductContract {}

class Product implements ProductContract
{
    #[Init(__NAMESPACE__ . '\\initialize')]
    public string $ready;

    public function __construct(public string $name)
    {
        State::$events[] = 'constructor';
    }
}

#[Lazy]
final class LazyProduct extends Product {}

#[Proxy]
final class ProxyProduct extends Product {}

function initialize(): string
{
    State::$events[] = 'init';
    if (State::$failInitialization) {
        State::$failInitialization = false;
        throw new RuntimeException('Retry mapped DTO initialization.');
    }
    return 'ready';
}

/**
 * @param array<string|int,mixed> $data
 * @return array{name:string}
 */
function mapName(array $data, #[EntryId('mapping.marker')] string $marker): array
{
    State::$events[] = 'afterMap:' . $marker;
    if (State::$callbackFailure !== null) {
        throw State::$callbackFailure;
    }
    $name = $data['name'] ?? null;
    if (!is_string($name)) {
        throw new LogicException('Expected the completed mapping before its callback.');
    }
    return ['name' => trim($name) . ':' . $marker];
}

// Runtime Closure constants keep the public API executable on PHP 8.4.
define(__NAMESPACE__ . '\\MAP_NAME', mapName(...));

function mapped(
    #[MapRequest(sources: [RequestDataSource::Query], map: ['raw' => 'name'], afterMap: MAP_NAME)]
    ProductContract $product,
): Product {
    State::$events[] = 'target';
    if (!$product instanceof Product) {
        throw new LogicException('Expected a mapped lifecycle product.');
    }
    return $product;
}

function builder(bool $uncached): ContainerBuilder
{
    $builder = (new ContainerBuilder())->addService('mapping.marker', 'first');
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

/** @param class-string<Product> $class */
function mappedContainer(string $class, bool $uncached): Container
{
    return builder($uncached)->addFactory(
        ProductContract::class,
        static function (ContainerValue $value, array $params) use ($class): Product {
            State::$events[] = 'factory';
            if (!$value->container instanceof FactoryInterface) {
                throw new LogicException('Expected the current container factory.');
            }
            $product = $value->container->make($class, $params);
            if (!$product instanceof Product) {
                throw new LogicException('Expected a lifecycle product.');
            }
            return $product;
        },
    )->build();
}

beforeEach(function (): void {
    State::$events = [];
    State::$failInitialization = false;
    State::$callbackFailure = null;
    State::$failTransientCallback = false;
    State::$transient = [];
    State::$reveal = null;
});

afterEach(function (): void {
    State::$callbackFailure = null;
    State::$transient = [];
    State::$reveal = null;
});

test('afterMap completes once before the DTO factory across eager lazy and proxy lifecycles', function (
    string $class,
    bool $uncached,
): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a lifecycle product class.');
    }
    $container = mappedContainer($class, $uncached);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['raw' => ' Ada ']);
    $product = $container->call(mapped(...), [ServerRequestInterface::class => $request]);
    if (!$product instanceof Product) {
        throw new LogicException('Expected the mapped product.');
    }
    $eager = $class === Product::class;

    expect(State::$events)->toBe($eager
        ? ['afterMap:first', 'factory', 'constructor', 'init', 'target']
        : ['afterMap:first', 'factory', 'target'])
        ->and($product->name)->toBe('Ada:first')
        ->and($product->ready)->toBe('ready')
        ->and(State::$events)->toBe($eager
            ? ['afterMap:first', 'factory', 'constructor', 'init', 'target']
            : ['afterMap:first', 'factory', 'target', 'constructor', 'init']);
})->with([Product::class, LazyProduct::class, ProxyProduct::class])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

test('a deferred DTO initialization retry never repeats its completed mapping callback or factory', function (string $class): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a deferred product class.');
    }
    $container = mappedContainer($class, false);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['raw' => 'Ada']);
    State::$failInitialization = true;
    $product = $container->call(mapped(...), [ServerRequestInterface::class => $request]);
    if (!$product instanceof Product) {
        throw new LogicException('Expected the deferred product.');
    }

    expect(State::$events)->toBe(['afterMap:first', 'factory', 'target'])
        ->and(fn(): string => $product->ready)->toThrow(ResolutionException::class, 'Retry mapped DTO initialization.')
        ->and(State::$events)->toBe(['afterMap:first', 'factory', 'target', 'constructor', 'init']);

    $container->set('mapping.marker', 'second');

    expect($product->ready)->toBe('ready')
        ->and($product->name)->toBe('Ada:first')
        ->and(State::$events)->toBe(['afterMap:first', 'factory', 'target', 'constructor', 'init', 'constructor', 'init']);

    $fresh = $container->call(mapped(...), [ServerRequestInterface::class => $request]);
    if (!$fresh instanceof Product) {
        throw new LogicException('Expected a fresh deferred product.');
    }
    expect($fresh->name)->toBe('Ada:second')
        ->and($fresh)->not->toBe($product)
        ->and(array_values(array_filter(State::$events, static fn(string $event): bool => str_starts_with($event, 'afterMap:'))))
        ->toBe(['afterMap:first', 'afterMap:second']);
})->with([LazyProduct::class, ProxyProduct::class]);

final class ForeignContainerFailure extends RuntimeException implements ContainerExceptionInterface {}

test('mapping callback failures preserve their public ownership and leave a fresh attempt usable', function (string $kind): void {
    $cause = match ($kind) {
        'owned' => new ResolutionException('owned mapping failure'),
        'foreign PSR' => new ForeignContainerFailure('foreign PSR mapping failure'),
        default => new RuntimeException('foreign mapping failure'),
    };
    State::$callbackFailure = $cause;
    $container = mappedContainer(Product::class, false);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['raw' => 'Ada']);
    try {
        $container->call(mapped(...), [ServerRequestInterface::class => $request]);
        Assert::fail('The mapping callback must fail before the DTO factory.');
    } catch (ResolutionException $error) {
        if ($kind === 'owned') {
            expect($error)->toBe($cause)->and($error->getPrevious())->toBeNull();
        } else {
            expect($error->getPrevious())->toBe($cause)
                ->and($error->parameterName)->toBe('product')
                ->and($error->parameterPosition)->toBe(0);
        }
    }
    expect(State::$events)->toBe(['afterMap:first']);

    State::$callbackFailure = null;
    $container->set('mapping.marker', 'second');
    $product = $container->call(mapped(...), [ServerRequestInterface::class => $request]);
    if (!$product instanceof Product) {
        throw new LogicException('Expected the recovered mapping result.');
    }
    expect($product->name)->toBe('Ada:second')
        ->and(State::$events)->toBe(['afterMap:first', 'afterMap:second', 'factory', 'constructor', 'init', 'target']);
})->with(['owned', 'foreign', 'foreign PSR']);

final readonly class TransientDependency
{
    public function __construct(public string $label = 'dependency') {}
}

final class TransientCapture
{
    public int $calls = 0;
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TransientMapping implements RequestDataExtractorInterface, CallbackMapperInterface
{
    public ?Closure $afterMap;

    public function __construct()
    {
        $capture = new TransientCapture();
        $this->afterMap = static function (
            array $data,
            #[Make(TransientDependency::class)] TransientDependency $dependency,
        ) use ($capture): array {
            State::$transient[] = WeakReference::create($dependency);
            State::$events[] = 'transient:' . $capture::class;
            if (State::$failTransientCallback) {
                throw new RuntimeException('Transient mapping failed.');
            }
            return ['payload' => $data['payload'], 'tag' => $dependency->label . ':' . ++$capture->calls];
        };
        foreach ([$this, $capture, $this->afterMap] as $value) {
            State::$transient[] = WeakReference::create($value);
        }
    }

    public function extract(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    public function transform(array $data): array
    {
        return $data;
    }
}

class PayloadProduct
{
    public function __construct(public object $payload, public string $tag) {}
}

#[Lazy]
final class LazyPayloadProduct extends PayloadProduct {}

#[Proxy]
final class ProxyPayloadProduct extends PayloadProduct {}

function transientContainer(bool $uncached): Container
{
    return builder($uncached)->addAttributeDefinition(static function (Container $container): AttributeDefinition {
        $handler = $container->get(RequestAttributeHandler::class);
        if (!$handler instanceof RequestAttributeHandler) {
            throw new LogicException('Expected the shared request attribute handler.');
        }
        return new AttributeDefinition(TransientMapping::class, $handler, [ValueProvider::class]);
    })->build();
}

test('request callback metadata releases transient closures captures inputs dependencies and results', function (
    string $mode,
    bool $uncached,
): void {
    $container = transientContainer($uncached);
    $target = match ($mode) {
        'array' => static fn(#[TransientMapping] array $data): array => $data,
        'lazy' => static fn(#[TransientMapping] LazyPayloadProduct $product): PayloadProduct => $product,
        'proxy' => static fn(#[TransientMapping] ProxyPayloadProduct $product): PayloadProduct => $product,
        default => static fn(#[TransientMapping] PayloadProduct $product): PayloadProduct => $product,
    };

    foreach ([1, 2] as $number) {
        $payload = (object) ['number' => $number];
        $discarded = new \stdClass();
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['payload' => $payload, 'discarded' => $discarded]);
        $inputReferences = array_map(WeakReference::create(...), [$payload, $discarded, $request]);
        $result = $container->call($target, [ServerRequestInterface::class => $request]);
        unset($payload, $discarded, $request);

        foreach (State::$transient as $reference) {
            expect($reference->get())->toBeNull();
        }

        if ($result instanceof PayloadProduct) {
            expect($result->payload)->toBe($inputReferences[0]->get())
                ->and($result->tag)->toBe('dependency:1');
            $resultReference = WeakReference::create($result);
        } else {
            expect($result)->toBe(['payload' => $inputReferences[0]->get(), 'tag' => 'dependency:1']);
            $resultReference = null;
        }
        expect($inputReferences[1]->get())->toBeNull()
            ->and($inputReferences[2]->get())->toBeNull();

        unset($result);
        gc_collect_cycles();
        expect($resultReference?->get())->toBeNull();
        foreach ($inputReferences as $reference) {
            expect($reference->get())->toBeNull();
        }
    }
})->with(['array', 'eager', 'lazy', 'proxy'])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

test('releasing a failed mapping exception releases callback state and allows a later successful attempt', function (): void {
    $container = transientContainer(false);
    $target = static fn(#[TransientMapping] array $data): array => $data;
    $payload = new \stdClass();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['payload' => $payload]);
    $inputReferences = array_map(WeakReference::create(...), [$payload, $request]);
    State::$failTransientCallback = true;
    $failure = null;
    try {
        $container->call($target, [ServerRequestInterface::class => $request]);
        Assert::fail('Expected the transient callback to fail.');
    } catch (ResolutionException $error) {
        $failure = $error;
    }
    expect($failure->getPrevious())->toBeInstanceOf(RuntimeException::class);
    State::$failTransientCallback = false;
    unset($failure, $error, $payload, $request);
    gc_collect_cycles();

    foreach ([...State::$transient, ...$inputReferences] as $reference) {
        expect($reference->get())->toBeNull();
    }

    $request = (new ServerRequest('GET', '/'))->withQueryParams(['payload' => 'recovered']);
    expect($container->call($target, [ServerRequestInterface::class => $request]))
        ->toBe(['payload' => 'recovered', 'tag' => 'dependency:1']);
});

/**
 * @param array<string|int,mixed> $data
 * @return array<string|int,mixed>
 */
function revealing(array $data): array
{
    State::$events[] = 'afterMap:reveal';
    State::$reveal?->__invoke();
    return $data;
}

/**
 * @param array<string|int,mixed> $data
 * @return array<string|int,mixed>
 */
function postMapping(array $data): array
{
    State::$events[] = 'transform';
    return $data + ['transformed' => true];
}

define(__NAMESPACE__ . '\\REVEAL', revealing(...));
define(__NAMESPACE__ . '\\POST_MAPPING', postMapping(...));

test('autoloading a later transformer inside afterMap preserves completed callback work', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'LateTransform_' . $suffix;
    $trigger = 'LoadingTrigger_' . $suffix;
    $function = 'mappedWithLateTransform_' . $suffix;
    $aliasClass = __NAMESPACE__ . '\\' . $alias;
    $triggerClass = __NAMESPACE__ . '\\' . $trigger;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        function %s(
            #[\Componenta\DI\Attribute\MapRequest(sources: [\Componenta\DI\Attribute\RequestDataSource::Query], afterMap: REVEAL), %s(POST_MAPPING)]
            array $data,
        ): array { State::$events[] = 'target'; return $data; }
        PHP,
        __NAMESPACE__,
        $function,
        $alias,
    ));
    $loads = 0;
    $loader = static function (string $class) use ($triggerClass, $trigger, $aliasClass, &$loads): void {
        if ($class === $triggerClass) {
            ++$loads;
            class_alias(Transform::class, $aliasClass);
            eval(sprintf('namespace %s; final class %s {}', __NAMESPACE__, $trigger));
        }
    };
    State::$reveal = static function () use ($triggerClass): void {
        class_exists($triggerClass);
    };
    spl_autoload_register($loader);
    $container = builder($uncached)->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['name' => 'Ada']);

    try {
        foreach ([1, 2] as $attempt) {
            expect($container->call(__NAMESPACE__ . '\\' . $function, [ServerRequestInterface::class => $request]))
                ->toBe(['name' => 'Ada', 'transformed' => true])
                ->and(State::$events)->toBe(array_merge(...array_fill(0, $attempt, ['afterMap:reveal', 'transform', 'target'])))
                ->and($loads)->toBe(1);
        }
    } finally {
        spl_autoload_unregister($loader);
        State::$reveal = null;
    }
})->with(['cached plans' => false, 'non-memoized plans' => true]);

test('an incompatible source revealed by afterMap rejects completion without replaying its callback', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'LateSource_' . $suffix;
    $function = 'mappedWithLateSource_' . $suffix;
    $aliasClass = __NAMESPACE__ . '\\' . $alias;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        function %s(
            #[\Componenta\DI\Attribute\MapRequest(sources: [\Componenta\DI\Attribute\RequestDataSource::Query], afterMap: REVEAL), %s('source')]
            array $data,
        ): array { State::$events[] = 'target'; return $data; }
        PHP,
        __NAMESPACE__,
        $function,
        $alias,
    ));
    State::$reveal = static function () use ($aliasClass): void {
        class_alias(ConfigAttribute::class, $aliasClass);
    };
    $container = builder($uncached)->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['name' => 'Ada']);

    foreach ([1, 2] as $_attempt) {
        expect(fn() => $container->call(__NAMESPACE__ . '\\' . $function, [ServerRequestInterface::class => $request]))
            ->toThrow(AttributeCompositionException::class)
            ->and(State::$events)->toBe(['afterMap:reveal']);
    }

    State::$reveal = null;
    $valid = static fn(#[MapRequest(sources: [RequestDataSource::Query], afterMap: REVEAL)] array $data): array => $data;
    expect($container->call($valid, [ServerRequestInterface::class => $request]))->toBe(['name' => 'Ada'])
        ->and(State::$events)->toBe(['afterMap:reveal', 'afterMap:reveal']);
})->with(['cached plans' => false, 'non-memoized plans' => true]);

final readonly class TransientInput
{
    public function __construct(public int $number) {}
}

test('native inline afterMap closures keep fresh local state and release their values and dependencies', function (bool $uncached): void {
    $target = eval(<<<'PHP'
    namespace Componenta\DI\Tests\Contract\RequestMappingCallbackLifecycle;
    return static fn(
        #[\Componenta\DI\Attribute\MapRequest(
            sources: [\Componenta\DI\Attribute\RequestDataSource::Query],
            afterMap: static function (
                array $data,
                #[\Componenta\DI\Attribute\Make(TransientDependency::class)] TransientDependency $dependency,
            ): array {
                static $calls = 0;
                static $local = new \stdClass();
                $input = $data['input'];
                if (!$input instanceof TransientInput) {
                    throw new \LogicException('Expected the transient mapped input.');
                }
                foreach ([$input, $local, $dependency] as $value) {
                    State::$transient[] = \WeakReference::create($value);
                }
                return ['number' => $input->number + ++$calls];
            },
        )]
        array $data,
    ): array => $data;
    PHP);
    if (!$target instanceof Closure) {
        throw new LogicException('Expected the native mapping callback target.');
    }
    $container = builder($uncached)->build();

    foreach ([10, 20] as $number) {
        $input = new TransientInput($number);
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['input' => $input]);
        expect($container->call($target, [ServerRequestInterface::class => $request]))->toBe(['number' => $number + 1]);
        unset($input, $request);
        gc_collect_cycles();
        foreach (State::$transient as $reference) {
            expect($reference->get())->toBeNull();
        }
    }
})->with(['cached plans' => false, 'non-memoized plans' => true])
    ->skip(PHP_VERSION_ID < 80500, 'Native closure attribute arguments require PHP 8.5.');
