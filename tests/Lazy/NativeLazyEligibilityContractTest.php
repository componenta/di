<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Lazy\NativeLazyEligibilityContract;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Throwable;
use WeakReference;

final class NativeLazyEligibilityEvents
{
    /** @var list<string> */
    public static array $events = [];
}

trait NativeLazyEligibilityLifecycle
{
    public function __construct()
    {
        NativeLazyEligibilityEvents::$events[] = 'constructor';
    }

    public function __destruct()
    {
        NativeLazyEligibilityEvents::$events[] = 'destructor';
    }
}

class NativeLazyEligibilityEmpty
{
    use NativeLazyEligibilityLifecycle;
}

final class NativeLazyEligibilityStaticOnly
{
    use NativeLazyEligibilityLifecycle;

    public static int $value = 0;
}

class NativeLazyEligibilityVirtualOnly
{
    use NativeLazyEligibilityLifecycle;

    public string $value {
        get {
            NativeLazyEligibilityEvents::$events[] = 'get hook';
            return 'virtual';
        }
        set {
            NativeLazyEligibilityEvents::$events[] = 'set hook';
        }
    }
}

final class NativeLazyEligibilityVirtualChild extends NativeLazyEligibilityVirtualOnly {}

final class NativeLazyEligibilityEmptyStdClass extends stdClass
{
    use NativeLazyEligibilityLifecycle;
}

final class NativeLazyEligibilityPrivateEmpty
{
    use NativeLazyEligibilityLifecycle;

    private function __construct()
    {
        NativeLazyEligibilityEvents::$events[] = 'private constructor';
    }
}

final class NativeLazyEligibilityProtectedEmpty
{
    use NativeLazyEligibilityLifecycle;

    protected function __construct()
    {
        NativeLazyEligibilityEvents::$events[] = 'protected constructor';
    }
}

interface NativeLazyEligibilityValue
{
    public function initialize(): void;

    public function read(): string;
}

trait NativeLazyEligibilityValueMethods
{
    public function initialize(): void
    {
        $this->value = 'initialized';
    }

    public function read(): string
    {
        return $this->value;
    }
}

class NativeLazyEligibilityPrivateParent implements NativeLazyEligibilityValue
{
    use NativeLazyEligibilityValueMethods;

    private string $value;
}

class NativeLazyEligibilityPrivateChild extends NativeLazyEligibilityPrivateParent {}

final class NativeLazyEligibilityPrivateGrandchild extends NativeLazyEligibilityPrivateChild {}

final class NativeLazyEligibilityBackedHook implements NativeLazyEligibilityValue
{
    use NativeLazyEligibilityValueMethods;

    public string $value {
        get {
            NativeLazyEligibilityEvents::$events[] = 'get hook';
            return $this->value;
        }
        set {
            NativeLazyEligibilityEvents::$events[] = 'set hook';
            $this->value = $value;
        }
    }
}

final readonly class NativeLazyEligibilityReadonly implements NativeLazyEligibilityValue
{
    public function __construct(public string $value) {}

    public function initialize(): void
    {
        $this->__construct('initialized');
    }

    public function read(): string
    {
        return $this->value;
    }
}

final class NativeLazyEligibilityPromoted implements NativeLazyEligibilityValue
{
    use NativeLazyEligibilityValueMethods;

    public function __construct(public string $value)
    {
        NativeLazyEligibilityEvents::$events[] = 'constructor';
    }
}

final class NativeLazyEligibilityBackedStdClass extends stdClass implements NativeLazyEligibilityValue
{
    use NativeLazyEligibilityValueMethods;

    public string $value;
}

final class NativeLazyEligibilityPrivateConstructor implements NativeLazyEligibilityValue
{
    use NativeLazyEligibilityValueMethods;

    public string $value;

    private function __construct()
    {
        NativeLazyEligibilityEvents::$events[] = 'private constructor';
    }
}

final class NativeLazyEligibilityProtectedConstructor implements NativeLazyEligibilityValue
{
    use NativeLazyEligibilityValueMethods;

    public string $value;

    protected function __construct()
    {
        NativeLazyEligibilityEvents::$events[] = 'protected constructor';
    }
}

interface NativeLazyEligibilityInterface {}

abstract class NativeLazyEligibilityAbstract {}

abstract class NativeLazyEligibilityBackedAbstract
{
    public string $value;
}

enum NativeLazyEligibilityEnum
{
    case Value;
}

class NativeLazyEligibilityInternalChild extends DateTimeImmutable {}

final class NativeLazyEligibilityBackedInternalGrandchild extends NativeLazyEligibilityInternalChild
{
    public string $value;
}

test('native lazy requests reject propertyless targets before allocating or invoking user code', function (string $class, bool $container, bool $proxy): void {
    NativeLazyEligibilityEvents::$events = [];
    $calls = 0;
    $factory = $container ? (new ContainerBuilder())->build() : new ProxyFactory();
    $callback = static function (object $target) use (&$calls): never {
        ++$calls;
        throw new LogicException('A rejected native lazy callback must not run.');
    };
    $requested = '\\' . strtolower($class);
    if (!class_exists($requested)) {
        throw new LogicException('The rejection fixture must identify a loaded class.');
    }
    $error = null;

    try {
        $result = $proxy
            ? $factory->makeProxy($requested, $callback)
            : $factory->makeLazy($requested, $callback);
        unset($result);
    } catch (ResolutionException $caught) {
        $error = $caught;
    }

    if ($error === null) {
        Assert::fail('A propertyless native lazy request must throw before allocating a target.');
    }
    expect($error->serviceId)->toBe($class)
        ->and($error->getPrevious())->toBeNull()
        ->and($error->getMessage())->toContain($proxy ? 'proxy' : 'ghost', 'backed instance properties', 'eager')
        ->and($calls)->toBe(0)
        ->and(NativeLazyEligibilityEvents::$events)->toBe([]);
})->with([
    'factory without properties' => [NativeLazyEligibilityEmpty::class, false],
    'factory static only' => [NativeLazyEligibilityStaticOnly::class, false],
    'factory virtual only' => [NativeLazyEligibilityVirtualOnly::class, false],
    'factory inherited virtual only' => [NativeLazyEligibilityVirtualChild::class, false],
    'factory stdClass' => [stdClass::class, false],
    'factory empty stdClass descendant' => [NativeLazyEligibilityEmptyStdClass::class, false],
    'factory private constructor without properties' => [NativeLazyEligibilityPrivateEmpty::class, false],
    'factory protected constructor without properties' => [NativeLazyEligibilityProtectedEmpty::class, false],
    'container without properties' => [NativeLazyEligibilityEmpty::class, true],
    'container static only' => [NativeLazyEligibilityStaticOnly::class, true],
    'container virtual only' => [NativeLazyEligibilityVirtualOnly::class, true],
])->with(['ghost' => false, 'proxy' => true]);

test('native propertyless diagnostics identify the declared class behind a class alias', function (bool $proxy, string $alias): void {
    if (!class_exists($alias, false)) {
        class_alias(NativeLazyEligibilityEmpty::class, $alias);
    }
    if (!class_exists($alias, false)) {
        throw new LogicException('The alias fixture must be loaded.');
    }
    NativeLazyEligibilityEvents::$events = [];
    $factory = new ProxyFactory();
    $callback = static function (object $target): never {
        throw new LogicException('The callback for a rejected class alias must not run.');
    };

    try {
        $proxy ? $factory->makeProxy($alias, $callback) : $factory->makeLazy($alias, $callback);
    } catch (ResolutionException $error) {
        expect($error->serviceId)->toBe(NativeLazyEligibilityEmpty::class)
            ->and($error->getPrevious())->toBeNull()
            ->and(NativeLazyEligibilityEvents::$events)->toBe([]);
        return;
    }
    Assert::fail('An alias must not bypass native propertyless rejection.');
})->with([
    'ghost' => [false, __NAMESPACE__ . '\\NativeLazyEligibilityGhostAlias'],
    'proxy' => [true, __NAMESPACE__ . '\\NativeLazyEligibilityProxyAlias'],
]);

test('native lazy eligibility keeps backed layouts deferred with native object identities', function (string $class, bool $proxy): void {
    /** @var class-string<NativeLazyEligibilityValue> $class */
    $reflection = new ReflectionClass($class);
    $factory = new ProxyFactory();
    $calls = 0;
    $received = null;
    $backing = null;
    NativeLazyEligibilityEvents::$events = [];

    $target = $proxy
        ? $factory->makeProxy($class, static function (NativeLazyEligibilityValue $target) use ($reflection, &$calls, &$received, &$backing): NativeLazyEligibilityValue {
            ++$calls;
            $received = $target;
            $backing = $reflection->newInstanceWithoutConstructor();
            $backing->initialize();
            return $backing;
        })
        : $factory->makeLazy($class, static function (NativeLazyEligibilityValue $target) use (&$calls, &$received): void {
            ++$calls;
            $received = $target;
            $target->initialize();
        });

    expect($reflection->isUninitializedLazyObject($target))->toBeTrue()
        ->and($calls)->toBe(0)
        ->and(NativeLazyEligibilityEvents::$events)->toBe([]);

    $initialized = $reflection->initializeLazyObject($target);

    expect($received)->toBe($target)
        ->and($initialized)->toBe($proxy ? $backing : $target)
        ->and($target->read())->toBe('initialized')
        ->and($target->read())->toBe('initialized')
        ->and($calls)->toBe(1)
        ->and($reflection->isUninitializedLazyObject($target))->toBeFalse();
    if ($proxy) {
        expect($target)->not->toBe($backing);
    }
})->with([
    'private grandparent slot' => NativeLazyEligibilityPrivateGrandchild::class,
    'backed get and set hooks' => NativeLazyEligibilityBackedHook::class,
    'readonly property' => NativeLazyEligibilityReadonly::class,
    'promoted property' => NativeLazyEligibilityPromoted::class,
    'stdClass descendant with a slot' => NativeLazyEligibilityBackedStdClass::class,
    'private constructor with a slot' => NativeLazyEligibilityPrivateConstructor::class,
    'protected constructor with a slot' => NativeLazyEligibilityProtectedConstructor::class,
])->with(['ghost' => false, 'proxy' => true]);

test('native allocation failures keep their original cause ahead of propertyless diagnostics', function (string $class, bool $proxy): void {
    /** @var class-string $class */
    $calls = 0;
    $factoryCallback = static function (object $target) use (&$calls): object {
        ++$calls;
        return $target;
    };
    $initializer = static function (object $target) use (&$calls): void {
        ++$calls;
    };
    $nativeFailure = null;
    try {
        $reflection = new ReflectionClass($class);
        $proxy ? $reflection->newLazyProxy($factoryCallback) : $reflection->newLazyGhost($initializer);
    } catch (Throwable $caught) {
        $nativeFailure = $caught;
    }
    if ($nativeFailure === null) {
        throw new LogicException('This fixture must be rejected by PHP native lazy allocation.');
    }

    $factory = new ProxyFactory();
    try {
        $proxy ? $factory->makeProxy($class, $factoryCallback) : $factory->makeLazy($class, $initializer);
    } catch (ResolutionException $error) {
        $previous = $error->getPrevious();
        expect($previous)->toBeInstanceOf($nativeFailure::class);
        expect($previous?->getMessage())->toBe($nativeFailure->getMessage())
            ->and($error->serviceId)->toBe($class)
            ->and($calls)->toBe(0);
        return;
    }
    throw new LogicException('The native failure must remain a DI resolution failure.');
})->with([
    'missing class' => __NAMESPACE__ . '\\NativeLazyEligibilityMissing',
    'interface' => NativeLazyEligibilityInterface::class,
    'trait' => NativeLazyEligibilityLifecycle::class,
    'abstract without a slot' => NativeLazyEligibilityAbstract::class,
    'abstract with a slot' => NativeLazyEligibilityBackedAbstract::class,
    'enum' => NativeLazyEligibilityEnum::class,
    'internal class' => DateTimeImmutable::class,
    'internal descendant without a slot' => NativeLazyEligibilityInternalChild::class,
    'internal grandchild with a slot' => NativeLazyEligibilityBackedInternalGrandchild::class,
])->with(['ghost' => false, 'proxy' => true]);

test('supported lazy callbacks keep deferred exception identity and can retry initialization', function (bool $container, bool $proxy): void {
    $factory = $container ? (new ContainerBuilder())->build() : new ProxyFactory();
    $failure = new RuntimeException('Deferred initialization failed.', 418);
    $attempts = 0;
    $backing = null;
    $class = NativeLazyEligibilityPromoted::class;
    $reflection = new ReflectionClass($class);

    $target = $proxy
        ? $factory->makeProxy($class, static function (NativeLazyEligibilityPromoted $target) use (&$attempts, $failure, &$backing): NativeLazyEligibilityPromoted {
            if (++$attempts === 1) {
                throw $failure;
            }
            return $backing = new NativeLazyEligibilityPromoted('initialized');
        })
        : $factory->makeLazy($class, static function (NativeLazyEligibilityPromoted $target) use (&$attempts, $failure): void {
            if (++$attempts === 1) {
                throw $failure;
            }
            $target->initialize();
        });

    expect($attempts)->toBe(0)
        ->and($reflection->isUninitializedLazyObject($target))->toBeTrue();
    $caught = null;
    try {
        $reflection->initializeLazyObject($target);
    } catch (RuntimeException $error) {
        $caught = $error;
    }
    expect($caught)->toBe($failure)
        ->and($attempts)->toBe(1)
        ->and($reflection->isUninitializedLazyObject($target))->toBeTrue();

    $initialized = $reflection->initializeLazyObject($target);

    expect($initialized)->toBe($proxy ? $backing : $target)
        ->and($target->read())->toBe('initialized')
        ->and($target->read())->toBe('initialized')
        ->and($attempts)->toBe(2)
        ->and($reflection->isUninitializedLazyObject($target))->toBeFalse();
})->with(['factory' => false, 'container' => true])->with(['ghost' => false, 'proxy' => true]);

test('lazy allocation retains the supplied callable route through target autoloading', function (
    bool $container,
    bool $proxy,
    bool $rebindDuringAutoload,
): void {
    $factory = $container ? new ContainerBuilder()->build() : new ProxyFactory();
    $original = new class () {
        public int $calls = 0;
        public string $value = 'original';

        public function initialize(NativeLazyEligibilityPromoted $target): void
        {
            ++$this->calls;
            $target->value = $this->value;
        }

        public function build(NativeLazyEligibilityPromoted $target): NativeLazyEligibilityPromoted
        {
            ++$this->calls;
            return new NativeLazyEligibilityPromoted($this->value);
        }

        public function unexpected(NativeLazyEligibilityPromoted $target): never
        {
            throw new LogicException('The caller rebound the supplied method during allocation.');
        }
    };
    $receiver = $original;
    $replacement = clone $original;
    $replacement->value = 'replacement';
    $method = $proxy ? 'build' : 'initialize';
    $callback = [&$receiver, &$method];
    $rebind = static function () use (&$receiver, &$method, $replacement): void {
        $receiver = $replacement;
        $method = 'unexpected';
    };
    /** @var class-string<NativeLazyEligibilityPromoted> $class */
    $class = __NAMESPACE__ . '\\DeferredLazyCallbackTarget_' . bin2hex(random_bytes(5));
    $loader = static function (string $name) use ($class, $rebindDuringAutoload, $rebind): void {
        if ($name === $class) {
            if ($rebindDuringAutoload) {
                $rebind();
            }
            class_alias(NativeLazyEligibilityPromoted::class, $class, false);
        }
    };
    spl_autoload_register($loader);
    try {
        $target = $proxy ? $factory->makeProxy($class, $callback) : $factory->makeLazy($class, $callback);
    } finally {
        spl_autoload_unregister($loader);
    }
    if (!$rebindDuringAutoload) {
        $rebind();
    }

    $original->value = 'updated business state';
    $reflection = new ReflectionClass($class);
    expect($reflection->isUninitializedLazyObject($target))->toBeTrue()
        ->and($original->calls)->toBe(0)
        ->and($replacement->calls)->toBe(0)
        ->and($target->read())->toBe('updated business state')
        ->and($original->calls)->toBe(1)
        ->and($replacement->calls)->toBe(0);
})->with(['factory' => false, 'container' => true])
    ->with(['ghost' => false, 'proxy' => true])
    ->with(['rebind during autoload' => true, 'rebind after allocation' => false]);

test('propertyless diagnostics release callback captures while their exception remains retained', function (bool $container, bool $proxy): void {
    $setting = ini_set('zend.exception_ignore_args', '1');
    if ($setting === false) {
        throw new LogicException('The test must isolate diagnostic ownership from PHP trace arguments.');
    }
    try {
        $factory = $container ? (new ContainerBuilder())->build() : new ProxyFactory();
        $captured = new stdClass();
        $reference = WeakReference::create($captured);
        $callback = static function (object $target) use ($captured): never {
            throw new LogicException('A rejected callback must not access capture ' . spl_object_id($captured));
        };
        $error = null;
        try {
            $proxy
                ? $factory->makeProxy(NativeLazyEligibilityEmpty::class, $callback)
                : $factory->makeLazy(NativeLazyEligibilityEmpty::class, $callback);
        } catch (ResolutionException $caught) {
            $error = $caught;
        }
        if ($error === null) {
            Assert::fail('The captured callback must be rejected with a retained resolution exception.');
        }

        unset($callback, $captured);
        gc_collect_cycles();

        expect($reference->get())->toBeNull()
            ->and($error->serviceId)->toBe(NativeLazyEligibilityEmpty::class)
            ->and($error->getPrevious())->toBeNull();
    } finally {
        ini_set('zend.exception_ignore_args', $setting);
    }
})->with(['factory' => false, 'container' => true])->with(['ghost' => false, 'proxy' => true]);
