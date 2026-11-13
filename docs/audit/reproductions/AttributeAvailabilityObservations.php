<?php

declare(strict_types=1);

namespace Componenta\DI\Audit\AttributeAvailability;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * One scenario per fresh PHP process, with an optional preloaded control:
 *
 * php -n docs/audit/reproductions/AttributeAvailabilityObservations.php between-resolutions
 * php -n docs/audit/reproductions/AttributeAvailabilityObservations.php between-resolutions preloaded
 *
 * Scenarios: between-resolutions, second-attempt-class, second-attempt-interface,
 * second-attempt-alias, throw-first, throw-second, unrelated-side-effect.
 *
 * For another snapshot, supply both COMPONENTA_DI_AUDIT_AUTOLOAD and
 * COMPONENTA_DI_AUDIT_SOURCE as absolute paths. Use that snapshot's generated
 * autoloader, or an adapter that remaps both optimized classmaps and autoloaded
 * DI functions. Merely changing a PSR-4 source path is insufficient. Source
 * verification rejects a mismatched loader before recording observations.
 *
 * Uses production ContainerFactory and Config; no test-support fixtures.
 * Records observations, including expected exceptions, rather than asserting
 * that historical autoloader-call counts are the future compatibility policy.
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
$source = realpath($source);
require $autoload;
if (realpath((new ReflectionClass(Container::class))->getFileName()) !== $source . '/Container.php'
    || realpath((new ReflectionFunction('Componenta\\DI\\Internal\\is_entry_class_eligible'))->getFileName()) !== $source . '/Internal/functions.php'
) {
    throw new RuntimeException('The selected autoloader loaded DI classes or functions from another snapshot.');
}

final class State
{
    public static int $loaderCalls = 0;
    public static int $constructors = 0;
    public static int $handlers = 0;
    /** @var list<string> */
    public static array $events = [];
}

abstract class Initializable
{
    public int $initializations = 0;

    public function __construct()
    {
        ++State::$constructors;
        State::$events[] = 'constructor';
    }

    public function initialize(): void
    {
        ++State::$handlers;
        ++$this->initializations;
        State::$events[] = 'setup';
    }
}

#[Missing('initialize')]
final class Target extends Initializable {}

#[Missing]
final class Contaminant {}

final class Unrelated
{
    public readonly bool $sideEffect;
    public readonly int $callsAtConstruction;

    public function __construct()
    {
        ++State::$constructors;
        State::$events[] = 'constructor';
        $this->sideEffect = class_exists(SideEffect::class, false);
        $this->callsAtConstruction = State::$loaderCalls;
    }
}

function container(): Container
{
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new RuntimeException('Expected the Componenta container.');
    }
    return $container;
}

/** @return array<string, mixed> */
function observe(callable $operation): array
{
    try {
        return ['value' => $operation(), 'exception' => null];
    } catch (Throwable $error) {
        return [
            'value' => null,
            'exception' => $error::class,
            'message' => $error->getMessage(),
            'previous' => $error->getPrevious()?->getMessage(),
        ];
    }
}

$scenario = $argv[1] ?? '';
$mode = $argv[2] ?? 'dynamic';
$scenarios = ['between-resolutions', 'second-attempt-class', 'second-attempt-interface', 'second-attempt-alias', 'throw-first', 'throw-second', 'unrelated-side-effect'];
if (!in_array($scenario, $scenarios, true) || !in_array($mode, ['dynamic', 'preloaded'], true)) {
    throw new RuntimeException('Usage: php AttributeAvailabilityObservations.php <' . implode('|', $scenarios) . '> [dynamic|preloaded]');
}
$preloaded = $mode === 'preloaded';
$name = Missing::class;
$observations = [];

if ($scenario === 'between-resolutions') {
    $ready = false;
    if ($preloaded) {
        class_alias(SetUp::class, $name);
    }
    $loader = static function (string $requested) use ($name, &$ready): void {
        if ($requested !== $name) {
            return;
        }
        ++State::$loaderCalls;
        State::$events[] = 'loader:' . (int) $ready;
        if ($ready) {
            class_alias(SetUp::class, $requested);
        }
    };
    $container = container();
    spl_autoload_register($loader);
    try {
        $observations[] = observe(static fn(): int => $container->make(Target::class)->initializations);
        $ready = true;
        State::$events[] = 'ready';
        $observations[] = observe(static fn(): int => $container->make(Target::class)->initializations);
    } finally {
        spl_autoload_unregister($loader);
    }
} elseif (str_starts_with($scenario, 'second-attempt-') || $scenario === 'throw-first' || $scenario === 'throw-second') {
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($name);
    $kind = substr($scenario, strlen('second-attempt-'));
    $define = static function () use ($name, $kind): void {
        match ($kind) {
            'interface' => eval('namespace ' . __NAMESPACE__ . '; interface Missing {}'),
            'alias' => class_alias(SetUp::class, $name),
            default => eval('namespace ' . __NAMESPACE__ . '; final class Missing {}'),
        };
    };
    if ($preloaded) {
        $define();
    }
    $loader = static function (string $requested) use ($name, $scenario, $define): void {
        if ($requested !== $name) {
            return;
        }
        $count = ++State::$loaderCalls;
        State::$events[] = 'loader:' . $count;
        if (($scenario === 'throw-first' && $count === 1) || ($scenario === 'throw-second' && $count === 2)) {
            throw new RuntimeException('loader-failure-' . $count);
        }
        if (str_starts_with($scenario, 'second-attempt-') && $count === 2) {
            $define();
            State::$events[] = 'defined';
        }
    };
    spl_autoload_register($loader);
    try {
        for ($index = 0; $index < 2; ++$index) {
            $observations[] = observe(static fn(): int => $registry->revision) + [
                'loaded_after_poll' => class_exists($name, false) || interface_exists($name, false),
                'loader_calls_after_poll' => State::$loaderCalls,
            ];
        }
    } finally {
        spl_autoload_unregister($loader);
    }
} else {
    $container = container();
    $container->make(Contaminant::class);
    if ($preloaded) {
        eval('namespace ' . __NAMESPACE__ . '; final class Missing {} final class SideEffect {}');
    }
    $loader = static function (string $requested) use ($name): void {
        if ($requested !== $name) {
            return;
        }
        ++State::$loaderCalls;
        State::$events[] = 'loader';
        if (!class_exists(SideEffect::class, false)) {
            eval('namespace ' . __NAMESPACE__ . '; final class SideEffect {}');
            State::$events[] = 'side-effect';
        }
    };
    spl_autoload_register($loader);
    try {
        $observations[] = observe(static function () use ($container): array {
            $value = $container->make(Unrelated::class);
            return ['side_effect_visible' => $value->sideEffect, 'calls_at_constructor' => $value->callsAtConstruction];
        });
    } finally {
        spl_autoload_unregister($loader);
    }
}

$checkedDeclarations = 0;
foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
    if (!str_starts_with($class, 'Componenta\\DI\\') || str_starts_with($class, 'Componenta\\DI\\Audit\\')) {
        continue;
    }
    $file = (new ReflectionClass($class))->getFileName();
    if (!is_string($file) || !str_starts_with(realpath($file), $source . '/')) {
        throw new RuntimeException('DI declaration was loaded from another snapshot: ' . $class);
    }
    ++$checkedDeclarations;
}

echo json_encode([
    'scenario' => $scenario,
    'preloaded' => $preloaded,
    'php' => PHP_VERSION,
    'source' => $source,
    'checked_declarations' => $checkedDeclarations,
    'observations' => $observations,
    'loader_calls' => State::$loaderCalls,
    'constructors' => State::$constructors,
    'handlers' => State::$handlers,
    'events' => State::$events,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
