<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PropertyTransform;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;
use WeakReference;

#[Attribute(Attribute::TARGET_ALL)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function builder(bool $uncached = false): ContainerBuilder
{
    $builder = new ContainerBuilder(new Config(['label' => '  ready  '], new Environment([])));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

final class State
{
    /** @var list<mixed> */
    public static array $inputs = [];
    public static ?object $entry = null;
    public static ?Throwable $error = null;
    /** @var list<WeakReference<object>> */
    public static array $references = [];
    public static bool $fail = false;
}

function trimmed(string $input): string
{
    State::$inputs[] = $input;
    return trim($input);
}

function bracketed(string $input): string
{
    State::$inputs[] = $input;
    return '[' . $input . ']';
}

function identity(mixed $input): mixed
{
    State::$inputs[] = $input;
    return $input;
}

function length(string $input): int
{
    State::$inputs[] = $input;
    return strlen($input);
}

function positive(int $input): bool
{
    State::$inputs[] = $input;
    return $input > 0;
}

function fails(mixed $input): never
{
    State::$inputs[] = $input;
    throw State::$error ?? new RuntimeException('property transform failure');
}

define(__NAMESPACE__ . '\\TRIMMED', trimmed(...));
define(__NAMESPACE__ . '\\BRACKETED', bracketed(...));
define(__NAMESPACE__ . '\\IDENTITY', identity(...));
define(__NAMESPACE__ . '\\LENGTH', length(...));
define(__NAMESPACE__ . '\\POSITIVE', positive(...));
define(__NAMESPACE__ . '\\FAILS', fails(...));

beforeEach(function (): void {
    State::$inputs = [];
    State::$entry = null;
    State::$error = null;
    State::$references = [];
    State::$fail = false;
});

afterEach(function (): void {
    State::$inputs = [];
    State::$entry = null;
    State::$error = null;
    State::$references = [];
});

abstract class ReadonlyState
{
    abstract public string $label { get; }
}

/** @return class-string<ReadonlyState> */
function readonlyTarget(): string
{
    $class = __NAMESPACE__ . '\\ReadonlyTarget';
    if (!class_exists($class, false)) {
        eval(<<<'PHP'
        namespace Componenta\DI\Tests\Contract\PropertyTransform;
        final class ReadonlyTarget extends ReadonlyState {
            #[\Componenta\DI\Attribute\Transform(TRIMMED), \Componenta\DI\Attribute\Config('label'), \Componenta\DI\Attribute\Transform(BRACKETED)]
            public readonly string $label;
        }
        PHP);
    }
    if (!is_a($class, ReadonlyState::class, true)) {
        throw new LogicException('Expected the readonly transformation fixture.');
    }
    return $class;
}

final class IntermediateTarget
{
    #[ConfigAttribute('label'), Transform(LENGTH), Transform(POSITIVE)]
    public bool $label;
}

final class ContextTarget
{
    #[Transform(IDENTITY)]
    public ?string $label = 'default';

    public function __construct()
    {
        State::$entry = $this;
    }
}

final class PromotedTarget
{
    public function __construct(#[Transform(TRIMMED)] public string $label) {}
}

final class InitializedReadonlyTarget
{
    #[Transform(TRIMMED)]
    public readonly string $label;

    public function __construct()
    {
        $this->label = 'existing';
    }
}

final class WriteOnlyTarget
{
    /** @var list<string> */
    public array $assigned = [];

    #[Transform(TRIMMED), ConfigAttribute('label'), Transform(BRACKETED)]
    public string $label {
        set(string $value) {
            $this->assigned[] = $value;
        }
    }
}

final class WiderSetterTarget
{
    public int $assignments = 0;
    private int $stored = 0;

    #[Transform(TRIMMED)]
    public int $number {
        get => $this->stored;
        set(int|string $value) {
            ++$this->assignments;
            $this->stored = (int) $value;
        }
    }
}

final class FailureTarget
{
    public int $assignments = 0;
    private string $stored = 'before';

    public function __construct()
    {
        State::$entry = $this;
    }

    #[Transform(TRIMMED), Transform(FAILS)]
    public string $label {
        get => $this->stored;
        set(string $value) {
            ++$this->assignments;
            $this->stored = $value;
        }
    }
}

final class StaticTarget
{
    #[Transform(TRIMMED)]
    public static string $label = 'before';
}

test('property transformations keep intermediate values outside a typed readonly property', function (bool $uncached): void {
    $container = builder($uncached)->build();
    expect($container->make(readonlyTarget())->label)->toBe('[ready]')
        ->and($container->make(IntermediateTarget::class)->label)->toBeTrue()
        ->and(State::$inputs)->toBe(['  ready  ', 'ready', '  ready  ', 9]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('standalone property transforms require an exact context key and preserve explicit null', function (bool $uncached): void {
    $container = builder($uncached)->build();
    try {
        $container->make(ContextTarget::class);
        Assert::fail('A declared mutable property default is not a source.');
    } catch (ResolutionException $error) {
        expect($error->propertyClass)->toBe(ContextTarget::class)
            ->and($error->propertyName)->toBe('label')
            ->and($error->getMessage())->toContain('missing context key "label"')
            ->and(State::$inputs)->toBe([]);
    }
    $failed = State::$entry;
    if (!$failed instanceof ContextTarget) {
        throw new LogicException('Expected the constructed source-less target.');
    }
    expect($failed->label)->toBe('default')
        ->and($container->make(ContextTarget::class, ['label' => null])->label)->toBeNull()
        ->and(State::$inputs)->toBe([null]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('promoted parameters transform once and initialized readonly properties are skipped', function (bool $uncached): void {
    $container = builder($uncached)->build();
    expect($container->make(PromotedTarget::class, ['label' => '  promoted  '])->label)->toBe('promoted')
        ->and($container->make(InitializedReadonlyTarget::class, ['label' => 'replacement'])->label)->toBe('existing')
        ->and(State::$inputs)->toBe(['  promoted  ']);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('write-only setters receive the final transformed pending value exactly once', function (bool $uncached): void {
    $container = builder($uncached)->build();
    expect($container->make(WriteOnlyTarget::class)->assigned)->toBe(['[ready]'])
        ->and(State::$inputs)->toBe(['  ready  ', 'ready']);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('property transforms retain native wider setter input semantics', function (bool $uncached): void {
    $target = builder($uncached)->build()->make(WiderSetterTarget::class, ['number' => '  42  ']);
    expect($target->number)->toBe(42)
        ->and($target->assignments)->toBe(1)
        ->and(State::$inputs)->toBe(['  42  ']);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('failed property transformations preserve callback effects but never partially assign the staged property', function (bool $uncached): void {
    $cause = new RuntimeException('property callback cause');
    State::$error = $cause;
    try {
        builder($uncached)->build()->make(FailureTarget::class, ['label' => '  changed  ']);
        Assert::fail('Expected the second transformation to fail.');
    } catch (ResolutionException $error) {
        expect($error->propertyClass)->toBe(FailureTarget::class)
            ->and($error->propertyName)->toBe('label')
            ->and($error->getPrevious())->toBe($cause);
    }
    $entry = State::$entry;
    if (!$entry instanceof FailureTarget) {
        throw new LogicException('Expected the failed target to remain observable.');
    }
    expect($entry->label)->toBe('before')
        ->and($entry->assignments)->toBe(0)
        ->and(State::$inputs)->toBe(['  changed  ', 'changed']);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('static and get-only virtual properties reject transformation before callback execution', function (bool $uncached): void {
    $class = __NAMESPACE__ . '\\GetOnlyTarget';
    if (!class_exists($class, false)) {
        eval(<<<'PHP'
        namespace Componenta\DI\Tests\Contract\PropertyTransform;
        final class GetOnlyTarget {
            #[\Componenta\DI\Attribute\Transform(TRIMMED)]
            public string $label { get => 'existing'; }
        }
        PHP);
    }
    if (!class_exists($class, false)) {
        throw new LogicException('Expected the get-only fixture.');
    }
    $container = builder($uncached)->build();
    expect(fn() => $container->make(StaticTarget::class, ['label' => 'replacement']))
        ->toThrow(ResolutionException::class, 'static properties')
        ->and(fn() => $container->make($class, ['label' => 'replacement']))
        ->toThrow(ResolutionException::class, 'without a set hook')
        ->and(State::$inputs)->toBe([]);
})->with(['cached plans' => false, 'uncached plans' => true]);

abstract class NativeLazyTarget
{
    public string $label;
}

test('native lazy property transformations retry with fresh closures and release failed callback state', function (bool $uncached): void {
    $name = 'LazyTransform_' . bin2hex(random_bytes(5));
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[\Componenta\DI\Attribute\Lazy]
        final class %s extends NativeLazyTarget {
            #[\Componenta\DI\Attribute\Transform(static function (string $input): string {
                static $count = 0;
                static $local = new \stdClass();
                State::$references[] = \WeakReference::create($local);
                State::$inputs[] = ++$count;
                if (State::$fail) { throw new \RuntimeException('retry transform'); }
                return trim($input);
            })]
            public string $label;
        }
        PHP,
        __NAMESPACE__,
        $name,
    ));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!is_a($class, NativeLazyTarget::class, true)) {
        throw new LogicException('Expected the lazy transformation fixture.');
    }
    $container = builder($uncached)->build();
    $entry = $container->make($class, ['label' => '  retry  ']);
    if (!$entry instanceof NativeLazyTarget) {
        throw new LogicException('Expected the lazy transformation target.');
    }
    expect(State::$inputs)->toBe([]);
    State::$fail = true;
    expect(static fn(): string => $entry->label)->toThrow(ResolutionException::class, 'retry transform');
    gc_collect_cycles();
    foreach (State::$references as $reference) {
        expect($reference->get())->toBeNull();
    }
    State::$fail = false;
    expect($entry->label)->toBe('retry')
        ->and(State::$inputs)->toBe([1, 1]);
    gc_collect_cycles();
    foreach (State::$references as $reference) {
        expect($reference->get())->toBeNull();
    }
    $reference = WeakReference::create($entry);
    unset($entry);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
})->with(['cached plans' => false, 'uncached plans' => true])
    ->skip(PHP_VERSION_ID < 80500, 'Native closure attribute arguments require PHP 8.5.');
