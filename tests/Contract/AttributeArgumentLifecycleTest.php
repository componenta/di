<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use WeakReference;

final class AttributeArgument
{
    public static int $constructions = 0;

    /** @var list<WeakReference<self>> */
    public static array $references = [];

    public readonly int $generation;

    public function __construct()
    {
        $this->generation = ++self::$constructions;
        self::$references[] = WeakReference::create($this);
    }
}

#[Lazy, SetUp('initialize', ['argument' => new AttributeArgument()])]
final class LazyAttributeArgumentTarget
{
    public int $generation;

    public function initialize(AttributeArgument $argument): void
    {
        $this->generation = $argument->generation;
    }
}

test('lazy attribute arguments are created only during initialization and are not retained as metadata', function (): void {
    AttributeArgument::$constructions = 0;
    AttributeArgument::$references = [];
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    expect($container->has(LazyAttributeArgumentTarget::class))->toBeTrue()
        ->and(AttributeArgument::$constructions)->toBe(0);

    $entry = $container->make(LazyAttributeArgumentTarget::class);

    expect(AttributeArgument::$constructions)->toBe(0)
        ->and($entry->generation)->toBe(1)
        ->and(AttributeArgument::$constructions)->toBe(1);

    unset($entry);
    gc_collect_cycles();

    expect(array_filter(
        AttributeArgument::$references,
        static fn(WeakReference $reference): bool => $reference->get() !== null,
    ))->toBe([]);
});


final readonly class MadeAttributeArgumentTarget
{
    public function __construct(public AttributeArgument $argument) {}
}

function makeAttributeArgument(
    #[\Componenta\DI\Attribute\Make(MadeAttributeArgumentTarget::class, ['argument' => new AttributeArgument()])]
    MadeAttributeArgumentTarget $value,
): int {
    return $value->argument->generation;
}

test('Make evaluates its argument objects once per invocation', function (bool $closure): void {
    AttributeArgument::$constructions = 0;
    AttributeArgument::$references = [];
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }
    $callable = $closure
        ? static fn(
            #[\Componenta\DI\Attribute\Make(MadeAttributeArgumentTarget::class, ['argument' => new AttributeArgument()])]
            MadeAttributeArgumentTarget $value,
        ): int => $value->argument->generation
        : __NAMESPACE__ . '\\makeAttributeArgument';

    expect($container->call($callable))->toBe(1)
        ->and($container->call($callable))->toBe(2)
        ->and(AttributeArgument::$constructions)->toBe(2);
})->with(['named function' => false, 'closure' => true]);


final class MutableArgumentOptions
{
    public string $label = 'declared';
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class ArgumentMetadataAttribute
{
    public function __construct(public MutableArgumentOptions $options) {}
}

final class ReadArgumentMetadataRule implements \Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface
{
    /** @var array{string, string}|null */
    public ?array $labels = null;

    /** @var WeakReference<MutableArgumentOptions>|null */
    public ?WeakReference $argument = null;

    public function validate(
        \Componenta\DI\Attribute\Composition\AttributeUsage $attribute,
        \Componenta\DI\Attribute\Composition\AttributeSet $set,
    ): void {
        $first = $attribute->arguments['options'] ?? null;
        if (!$first instanceof MutableArgumentOptions) {
            throw new \LogicException('Expected the declared argument options.');
        }
        $original = $first->label;
        $first->label = 'changed locally';
        $second = $attribute->arguments['options'] ?? null;
        if (!$second instanceof MutableArgumentOptions) {
            throw new \LogicException('Expected the declared argument options.');
        }

        $this->labels = [$original, $second->label];
        $this->argument = WeakReference::create($first);
    }
}

function readArgumentMetadata(
    #[ArgumentMetadataAttribute(options: new MutableArgumentOptions())] string $value,
): string {
    return $value;
}

test('composition rules read fresh argument values without retaining them in a shared plan', function (): void {
    $rule = new ReadArgumentMetadataRule();
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            \Componenta\DI\ConfigKey::ATTRIBUTE_DEFINITIONS => [
                new \Componenta\DI\Attribute\Composition\AttributeDefinition(
                    ArgumentMetadataAttribute::class,
                    rules: [$rule],
                ),
            ],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    expect($container->call(__NAMESPACE__ . '\\readArgumentMetadata', ['value' => 'provided']))
        ->toBe('provided')
        ->and($rule->labels)->toBe(['declared', 'declared'])
        ->and($rule->argument)->not->toBeNull()
        ->and($rule->argument?->get())->toBeNull();
});
