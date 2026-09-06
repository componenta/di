<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;

final class LoadProxyAliasArgument
{
    public static int $constructions = 0;

    public function __construct(string $alias)
    {
        ++self::$constructions;
        if (!class_exists($alias, false)) {
            class_alias(Proxy::class, $alias);
        }
    }
}

final class ArgumentLoadedProxyProduct
{
    public static int $constructions = 0;

    public function __construct(public LoadProxyAliasArgument $argument)
    {
        ++self::$constructions;
    }
}

abstract class ArgumentLoadedProxyPropertyState
{
    public ArgumentLoadedProxyProduct $value;
}

eval(<<<'PHP'
namespace Componenta\DI\Tests\Contract;

function argumentLoadedProxyParameter(
    #[\Componenta\DI\Attribute\Make(ArgumentLoadedProxyProduct::class, ['argument' => new LoadProxyAliasArgument(__NAMESPACE__ . '\ArgumentParameterProxy')]), ArgumentParameterProxy]
    ArgumentLoadedProxyProduct $value,
): ArgumentLoadedProxyProduct {
    return $value;
}

final class ArgumentLoadedProxyProperty extends ArgumentLoadedProxyPropertyState
{
    #[\Componenta\DI\Attribute\Make(ArgumentLoadedProxyProduct::class, ['argument' => new LoadProxyAliasArgument(__NAMESPACE__ . '\ArgumentPropertyProxy')]), ArgumentPropertyProxy]
    public ArgumentLoadedProxyProduct $value;
}
PHP);

test('attributes loaded by Make arguments apply immediately without constructing the arguments twice', function (bool $property): void {
    LoadProxyAliasArgument::$constructions = 0;
    ArgumentLoadedProxyProduct::$constructions = 0;
    $container = (new ContainerBuilder())->build();
    $propertyClass = __NAMESPACE__ . '\ArgumentLoadedProxyProperty';
    if (!is_a($propertyClass, ArgumentLoadedProxyPropertyState::class, true)) {
        throw new LogicException('Expected the property target fixture.');
    }

    for ($iteration = 1; $iteration <= 2; ++$iteration) {
        if ($property) {
            $owner = $container->make($propertyClass);
            if (!$owner instanceof ArgumentLoadedProxyPropertyState) {
                throw new LogicException('Expected the property owner.');
            }
            $product = $owner->value;
        } else {
            $product = $container->call(__NAMESPACE__ . '\argumentLoadedProxyParameter');
        }
        if (!$product instanceof ArgumentLoadedProxyProduct) {
            throw new LogicException('Expected the declared product type.');
        }

        expect(new ReflectionClass(ArgumentLoadedProxyProduct::class)->isUninitializedLazyObject($product))->toBeTrue()
            ->and(ArgumentLoadedProxyProduct::$constructions)->toBe($iteration - 1)
            ->and(LoadProxyAliasArgument::$constructions)->toBe($iteration)
            ->and($product->argument)->toBeInstanceOf(LoadProxyAliasArgument::class)
            ->and(ArgumentLoadedProxyProduct::$constructions)->toBe($iteration);
    }
})->with(['parameter' => false, 'property' => true]);

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ArgumentLoadedPredecessor {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LoadPredecessorAttribute
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
        if (!class_exists(__NAMESPACE__ . '\PredecessorFromAttribute', false)) {
            class_alias(ArgumentLoadedPredecessor::class, __NAMESPACE__ . '\PredecessorFromAttribute');
        }
    }
}

final readonly class ArgumentLoadingTransformer implements ParameterAttributeHandlerInterface
{
    public function __construct(private string $suffix) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$value->resolved || !is_string($value->value)) {
            throw new LogicException('Expected a resolved string to transform.');
        }
        return ParameterAttributeValue::resolved($value->value . $this->suffix);
    }
}

eval(<<<'PHP'
namespace Componenta\DI\Tests\Contract;
function attributeLoadedPredecessor(
    #[LoadPredecessorAttribute, PredecessorFromAttribute] string $value,
): string {
    return $value;
}
PHP);

test('a predecessor loaded by an attribute constructor runs before its handler without reconstructing the attribute', function (): void {
    LoadPredecessorAttribute::$constructions = 0;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ArgumentLoadedPredecessor::class,
            new ArgumentLoadingTransformer(':first'),
            [ValueTransformer::class],
            before: [LoadPredecessorAttribute::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            LoadPredecessorAttribute::class,
            new ArgumentLoadingTransformer(':second'),
            [ValueTransformer::class],
        ))
        ->build();

    expect($container->call(__NAMESPACE__ . '\attributeLoadedPredecessor', ['value' => 'value']))->toBe('value:first:second')
        ->and(LoadPredecessorAttribute::$constructions)->toBe(1)
        ->and($container->call(__NAMESPACE__ . '\attributeLoadedPredecessor', ['value' => 'next']))->toBe('next:first:second')
        ->and(LoadPredecessorAttribute::$constructions)->toBe(2);
});

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ArgumentLoadedSource {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LoadAuthoritativeSourceAttribute
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
        if (!class_exists(__NAMESPACE__ . '\SourceFromAttribute', false)) {
            class_alias(ArgumentLoadedSource::class, __NAMESPACE__ . '\SourceFromAttribute');
        }
    }
}

eval(<<<'PHP'
namespace Componenta\DI\Tests\Contract;
function argumentLoadedAuthoritativeSource(
    #[LoadAuthoritativeSourceAttribute, SourceFromAttribute] string $value,
): string {
    return $value;
}
PHP);

test('an authoritative source loaded before handlers start takes precedence over caller input', function (): void {
    LoadAuthoritativeSourceAttribute::$constructions = 0;
    $sourceHandler = new class () implements ParameterAttributeHandlerInterface {
        public function resolveParameter(
            object $attribute,
            ParameterTarget $target,
            ParameterResolutionContext $context,
            AttributePlan $plan,
            ParameterAttributeValue $value,
        ): ParameterAttributeValue {
            return $value->resolved ? $value : ParameterAttributeValue::resolved('source');
        }
    };
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ArgumentLoadedSource::class,
            $sourceHandler,
            [AuthoritativeValueProvider::class],
            before: [LoadAuthoritativeSourceAttribute::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            LoadAuthoritativeSourceAttribute::class,
            new ArgumentLoadingTransformer(':transformed'),
            [ValueTransformer::class],
        ))
        ->build();

    expect($container->call(__NAMESPACE__ . '\argumentLoadedAuthoritativeSource', ['value' => 'caller']))
        ->toBe('source:transformed')
        ->and(LoadAuthoritativeSourceAttribute::$constructions)->toBe(1);
});
