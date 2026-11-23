<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use Reflector;

use function Componenta\DI\Tests\Support\fixtureInstance;

final class SelectedMakeProduct
{
    public static int $constructions = 0;
    public string $value;

    public function __construct()
    {
        ++self::$constructions;
        $this->value = 'ready';
    }
}

final class SelectedMakeProperty
{
    #[Make, Proxy]
    public SelectedMakeProduct $value;
}

function selectedMakeParameter(#[Make, Proxy] SelectedMakeProduct $value): SelectedMakeProduct
{
    return $value;
}

it('does not activate an unregistered Proxy beside Make', function (bool $property): void {
    SelectedMakeProduct::$constructions = 0;
    $runtime = new ResolutionRuntime();
    [$container] = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addAttributeDefinition(static fn(Container $container): AttributeDefinition => new AttributeDefinition(
            Make::class,
            new MakeHandler($container, $runtime->proxies, $runtime->plans),
            [ValueProvider::class],
        ))
        ->assemble($runtime);

    $product = $property
        ? fixtureInstance($container->make(SelectedMakeProperty::class), SelectedMakeProperty::class)->value
        : $container->call(__NAMESPACE__ . '\selectedMakeParameter');

    if (!$product instanceof SelectedMakeProduct) {
        throw new \LogicException('Expected the declared product type.');
    }

    expect(new ReflectionClass(SelectedMakeProduct::class)->isUninitializedLazyObject($product))->toBeFalse()
        ->and(SelectedMakeProduct::$constructions)->toBe(1);
})->with(['property' => true, 'parameter' => false]);

final class ObservedMakeOptions
{
    /** @var list<string> */
    public static array $retired = [];
    public string $state = 'declared';

    public function __destruct()
    {
        self::$retired[] = $this->state;
    }
}

final readonly class ObservedMakeProduct
{
    public function __construct(public ObservedMakeOptions $options) {}
}

final class ObservedMakeOwner
{
    #[Proxy(ObservedMakeProduct::class), Make(ObservedMakeProduct::class, ['options' => new ObservedMakeOptions()])]
    public ObservedMakeProduct $product;
}

test('a custom Make handler receives its own arguments alongside the built-in Proxy', function (bool $customFirst): void {
    ObservedMakeOptions::$retired = [];
    $di = new ContainerBuilder()->build();
    $registry = new AttributeDefinitionRegistry();
    $plans = new AttributePlanBuilder($registry);
    $registry->register(new AttributeDefinition(
        Proxy::class,
        new MakeHandler($di, $di, $plans),
        before: $customFirst ? [] : [Make::class],
        after: $customFirst ? [Make::class] : [],
    ));
    $registry->register(new AttributeDefinition(Make::class, new class () implements AttributeHandlerInterface {
        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            $options = $attribute instanceof Make ? ($attribute->params['options'] ?? null) : null;
            if (!$options instanceof ObservedMakeOptions) {
                throw new LogicException('Expected the custom handler options.');
            }
            $options->state = 'custom handler';
        }
    }));
    $pipeline = new ObjectPipeline(new AttributeProcessor($plans), new ParametersResolver($plans), $di);

    $owner = fixtureInstance($pipeline->create(ObservedMakeOwner::class), ObservedMakeOwner::class);
    expect($owner->product->options->state)->toBe('declared')
        ->and(ObservedMakeOptions::$retired)->toBe(['custom handler']);
})->with(['custom handler after Proxy' => false, 'custom handler before Proxy' => true]);

final class InferredMixedProxyProperty
{
    #[Make(SelectedMakeProduct::class), Proxy]
    public mixed $value;
}

final class InferredUntypedProxyProperty
{
    /** @var mixed */
    #[Make(SelectedMakeProduct::class), Proxy]
    public $value;
}

test('Proxy infers its concrete class from Make when the injection point has no class type', function (string $kind): void {
    SelectedMakeProduct::$constructions = 0;
    $container = (new ContainerBuilder())->build();
    $mixed = static fn(#[Make(SelectedMakeProduct::class), Proxy] mixed $value): mixed => $value;
    $untyped = static fn(#[Make(SelectedMakeProduct::class), Proxy] $value): mixed => $value;
    $resolve = match ($kind) {
        'mixed property' => static fn(): mixed => fixtureInstance($container->make(InferredMixedProxyProperty::class), InferredMixedProxyProperty::class)->value,
        'untyped property' => static fn(): mixed => fixtureInstance($container->make(InferredUntypedProxyProperty::class), InferredUntypedProxyProperty::class)->value,
        'mixed parameter' => static fn(): mixed => $container->call($mixed),
        'untyped parameter' => static fn(): mixed => $container->call($untyped),
        default => throw new LogicException('Unknown proxy inference target.'),
    };
    $previous = null;

    foreach ([1, 2] as $attempt) {
        $product = $resolve();
        if (!$product instanceof SelectedMakeProduct) {
            throw new LogicException('Expected the concrete class selected by Make.');
        }
        expect(new ReflectionClass(SelectedMakeProduct::class)->isUninitializedLazyObject($product))->toBeTrue()
            ->and(SelectedMakeProduct::$constructions)->toBe($attempt - 1)
            ->and($product->value)->toBe('ready')
            ->and(SelectedMakeProduct::$constructions)->toBe($attempt);
        if ($previous !== null) {
            expect($product)->not->toBe($previous);
        }
        $previous = $product;
    }
})->with(['mixed property', 'untyped property', 'mixed parameter', 'untyped parameter']);

final class UnusedMakeArgument
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

final class SelectedProxyProperty
{
    #[Make('unregistered.make.entry', ['argument' => new UnusedMakeArgument()]), Proxy]
    public SelectedMakeProduct $value;
}

function selectedProxyParameter(
    #[Make('unregistered.make.entry', ['argument' => new UnusedMakeArgument()]), Proxy]
    SelectedMakeProduct $value,
): SelectedMakeProduct {
    return $value;
}

it('does not read an unregistered Make or evaluate its arguments beside Proxy', function (bool $property): void {
    SelectedMakeProduct::$constructions = 0;
    UnusedMakeArgument::$constructions = 0;
    $runtime = new ResolutionRuntime();
    [$container] = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addAttributeDefinition(static fn(Container $container): AttributeDefinition => new AttributeDefinition(
            Proxy::class,
            new MakeHandler($container, $runtime->proxies, $runtime->plans),
        ))
        ->assemble($runtime);

    $product = $property
        ? fixtureInstance($container->make(SelectedProxyProperty::class), SelectedProxyProperty::class)->value
        : $container->call(__NAMESPACE__ . '\selectedProxyParameter');

    if (!$product instanceof SelectedMakeProduct) {
        throw new \LogicException('Expected the declared product type.');
    }

    expect(new ReflectionClass(SelectedMakeProduct::class)->isUninitializedLazyObject($product))->toBeTrue()
        ->and(SelectedMakeProduct::$constructions)->toBe(0)
        ->and(UnusedMakeArgument::$constructions)->toBe(0)
        ->and($product->value)->toBe('ready')
        ->and(SelectedMakeProduct::$constructions)->toBe(1);
})->with(['property' => true, 'parameter' => false]);
