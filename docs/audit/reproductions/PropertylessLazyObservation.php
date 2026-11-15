<?php

declare(strict_types=1);

namespace Componenta\DI\Audit\PropertylessLazy;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * Observe native objects, public DI calls, and the rejected eager-fallback spike.
 *
 * php docs/audit/reproductions/PropertylessLazyObservation.php
 *
 * For a different installation set both COMPONENTA_DI_AUDIT_AUTOLOAD and
 * COMPONENTA_DI_AUDIT_SOURCE. The latter names that installation's src directory.
 * An optimized classmap and eager DI functions must point to the selected source.
 * Source mismatch fails before observations. This is an audit reproduction,
 * not a test declaring silently skipped initialization to be desirable behavior.
 */
$repository = dirname(__DIR__, 3);
$autoload = getenv('COMPONENTA_DI_AUDIT_AUTOLOAD');
$source = getenv('COMPONENTA_DI_AUDIT_SOURCE');
$hasAutoload = is_string($autoload) && $autoload !== '';
$hasSource = is_string($source) && $source !== '';
if ($hasAutoload !== $hasSource) {
    throw new RuntimeException('Supply both COMPONENTA_DI_AUDIT_AUTOLOAD and COMPONENTA_DI_AUDIT_SOURCE, or neither.');
}
$autoload = $hasAutoload ? $autoload : $repository . '/vendor/autoload.php';
$source = $hasSource ? $source : $repository . '/src';
if (!is_file($autoload) || !is_dir($source)) {
    throw new RuntimeException('The selected DI autoloader or source directory does not exist.');
}
$canonicalPath = static function (string $path): string|false {
    $canonical = realpath($path);
    return $canonical === false ? false : str_replace(DIRECTORY_SEPARATOR, '/', $canonical);
};
$source = $canonicalPath($source);
require $autoload;
$origins = [];
foreach ([Container::class, ContainerFactory::class, ProxyFactory::class, ObjectPipeline::class] as $class) {
    $path = (new ReflectionClass($class))->getFileName();
    $relative = str_replace('\\', '/', substr($class, strlen('Componenta\\DI\\'))) . '.php';
    if (!is_string($source) || !is_string($path) || $canonicalPath($path) !== $source . '/' . $relative) {
        throw new RuntimeException('The selected autoloader loaded another DI snapshot: ' . $class);
    }
    $origins[$class] = ['file' => $canonicalPath($path), 'sha256' => hash_file('sha256', $path)];
}
$functionFile = (new ReflectionFunction('Componenta\\DI\\Internal\\is_entry_class_eligible'))->getFileName();
if (!is_string($functionFile) || $canonicalPath($functionFile) !== $source . '/Internal/functions.php') {
    throw new RuntimeException('The selected autoloader loaded DI functions from another snapshot.');
}

final class Trace
{
    /** @var list<string> */
    public static array $events = [];
}

class NoProperties
{
    public function __construct(string $value = 'constructed')
    {
        Trace::$events[] = 'constructor';
    }

    public function setUp(): void
    {
        Trace::$events[] = 'setup';
    }
}

class StaticOnly extends NoProperties
{
    public static int $counter = 0;
}

class VirtualOnly extends NoProperties
{
    public string $value {
        get => 'virtual';
        set {
            Trace::$events[] = 'virtual-set:' . $value;
        }
    }
}

class PrivateBackedParent extends NoProperties
{
    private int $state = 0;

    public function touch(): int
    {
        return $this->state;
    }
}

final class InheritedPrivateBacked extends PrivateBackedParent {}

class BackedHook extends NoProperties
{
    public string $value = 'backed' {
        get => $this->value;
        set {
            $this->value = $value;
        }
    }
}

final class EmptyStdClassChild extends \stdClass {}

final class BackedStdClassChild extends \stdClass
{
    public int $value = 0;
}

final class InternalDescendant extends \DateTimeImmutable {}

#[Lazy, SetUp('setUp')]
final class LazyNoProperties extends NoProperties {}

#[Proxy, SetUp('setUp')]
final class ProxyNoProperties extends NoProperties {}

#[Lazy, SetUp('setUp')]
final class LazyStaticOnly extends StaticOnly {}

#[Proxy, SetUp('setUp')]
final class ProxyStaticOnly extends StaticOnly {}

#[Lazy, SetUp('setUp')]
final class LazyVirtualOnly extends VirtualOnly {}

#[Proxy, SetUp('setUp')]
final class ProxyVirtualOnly extends VirtualOnly {}

#[Lazy, SetUp('setUp')]
final class LazyInheritedPrivate extends PrivateBackedParent {}

#[Proxy, SetUp('setUp')]
final class ProxyInheritedPrivate extends PrivateBackedParent {}

#[NoConstructor, Lazy, SetUp('setUp')]
final class NoConstructorLazy extends NoProperties {}

#[NoConstructor, Proxy, SetUp('setUp')]
final class NoConstructorProxy extends NoProperties {}

final class InjectedFactoryProduct
{
    #[Make('propertyless.product'), Proxy(NoProperties::class)]
    public NoProperties $product;
}

final class Destructible extends NoProperties
{
    public function __destruct()
    {
        Trace::$events[] = 'destructor';
    }
}

/** @param array<string,mixed> $definitions */
function container(array $definitions = []): Container
{
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions($definitions),
    )->container;
    if (!$container instanceof Container) {
        throw new RuntimeException('Expected the Componenta container.');
    }
    return $container;
}

/** @return array<string,mixed> */
function observe(callable $operation): array
{
    try {
        return ['result' => $operation(), 'exception' => null];
    } catch (Throwable $error) {
        return [
            'result' => null,
            'exception' => $error::class,
            'message' => $error->getMessage(),
            'previous' => $error->getPrevious()?->getMessage(),
        ];
    }
}

$native = [];
$publicFactory = [];
foreach ([NoProperties::class, StaticOnly::class, VirtualOnly::class, InheritedPrivateBacked::class, BackedHook::class] as $class) {
    foreach (['ghost', 'proxy'] as $strategy) {
        foreach ([true, false] as $useNative) {
            $record = observe(static function () use ($class, $strategy, $useNative): array {
                $calls = 0;
                $backing = new $class('backing');
                Trace::$events = [];
                $reflection = new ReflectionClass($class);
                $callback = $strategy === 'ghost'
                    ? static function (NoProperties $entry) use (&$calls): void {
                        ++$calls;
                        $entry->__construct('ghost');
                    }
                : static function (NoProperties $entry) use (&$calls, $backing): NoProperties {
                    ++$calls;
                    return $backing;
                };
                $entry = $useNative
                    ? ($strategy === 'ghost' ? $reflection->newLazyGhost($callback) : $reflection->newLazyProxy($callback))
                    : ($strategy === 'ghost' ? (new ProxyFactory())->makeLazy($class, $callback) : (new ProxyFactory())->makeProxy($class, $callback));
                $before = ['lazy' => $reflection->isUninitializedLazyObject($entry), 'calls' => $calls];
                $reflection->initializeLazyObject($entry);
                return [
                    'before_explicit_initialization' => $before,
                    'calls_after_explicit_initialization' => $calls,
                    // A valid native proxy intentionally has its own identity.
                    'returned_is_factory_product' => $strategy === 'proxy' ? $entry === $backing : null,
                ];
            });
            $key = $class . ':' . $strategy;
            if ($useNative) {
                $native[$key] = $record;
            } else {
                $publicFactory[$key] = $record;
            }
        }
    }
}

$nativeEligibility = [];
foreach ([\stdClass::class, EmptyStdClassChild::class, BackedStdClassChild::class, \DateTimeImmutable::class, InternalDescendant::class] as $class) {
    foreach (['ghost', 'proxy'] as $strategy) {
        $nativeEligibility[$class . ':' . $strategy] = observe(static function () use ($class, $strategy): array {
            $reflection = new ReflectionClass($class);
            $calls = 0;
            $callback = $strategy === 'ghost'
                ? static function (object $entry) use (&$calls): void {
                    ++$calls;
                }
            : static function (object $entry) use (&$calls, $class): object {
                ++$calls;
                return new $class();
            };
            $entry = $strategy === 'ghost' ? $reflection->newLazyGhost($callback) : $reflection->newLazyProxy($callback);
            $lazy = $reflection->isUninitializedLazyObject($entry);
            $reflection->initializeLazyObject($entry);
            return ['lazy_before_initialization' => $lazy, 'callback_calls' => $calls];
        });
    }
}

$pipeline = [];
foreach ([
    LazyNoProperties::class, ProxyNoProperties::class,
    LazyStaticOnly::class, ProxyStaticOnly::class,
    LazyVirtualOnly::class, ProxyVirtualOnly::class,
    LazyInheritedPrivate::class, ProxyInheritedPrivate::class,
    NoConstructorLazy::class, NoConstructorProxy::class,
] as $class) {
    $pipeline[$class] = observe(static function () use ($class): array {
        Trace::$events = [];
        $container = container();
        $entry = $container->get($class);
        $reflection = new ReflectionClass($class);
        $before = ['lazy' => $reflection->isUninitializedLazyObject($entry), 'events' => Trace::$events];
        $reflection->initializeLazyObject($entry);
        return [
            'before_explicit_initialization' => $before,
            'events_after_explicit_initialization' => Trace::$events,
            'shared_identity_retained' => $container->get($class) === $entry,
        ];
    });
}

$injectedFactory = observe(static function (): array {
    $calls = 0;
    $expected = new NoProperties('factory-product');
    Trace::$events = [];
    $container = container(['factories' => [
        'propertyless.product' => static function (ContainerValue $container, array $context) use (&$calls, $expected): object {
            ++$calls;
            return $expected;
        },
    ]]);
    $owner = $container->make(InjectedFactoryProduct::class);
    $reflection = new ReflectionClass(NoProperties::class);
    $reflection->initializeLazyObject($owner->product);
    return [
        'factory_calls' => $calls,
        'returned_is_factory_product' => $owner->product === $expected,
    ];
});

$naiveFallback = observe(static function (): array {
    Trace::$events = [];
    $reflection = new ReflectionClass(Destructible::class);
    $calls = 0;
    $produced = null;
    $factory = static function (object $placeholder) use (&$calls, &$produced): Destructible {
        ++$calls;
        $entry = new Destructible('factory-product');
        $produced = WeakReference::create($entry);
        return $entry;
    };
    // Deliberately rejected prototype, isolated from production ProxyFactory.
    $create = static function () use ($reflection, $factory): object {
        $placeholder = $reflection->newLazyProxy($factory);
        return $reflection->isUninitializedLazyObject($placeholder) ? $placeholder : $factory($placeholder);
    };
    $entry = $create();
    $eventsAtReturn = Trace::$events;
    $returnsFactoryProduct = $entry === $produced?->get();
    unset($entry);
    return [
        'factory_calls' => $calls,
        'returned_is_factory_product' => $returnsFactoryProduct,
        'events_at_return' => $eventsAtReturn,
        'events_after_release' => Trace::$events,
        'production_code_modified' => false,
    ];
});

echo json_encode([
    'schema_version' => 1,
    'php_version' => PHP_VERSION,
    'php_binary' => PHP_BINARY,
    'source_root' => $source,
    'sources' => $origins,
    'native' => $native,
    'native_eligibility' => $nativeEligibility,
    'public_factory' => $publicFactory,
    'attributed_pipeline' => $pipeline,
    'injected_factory' => $injectedFactory,
    'rejected_naive_proxy_fallback' => $naiveFallback,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
