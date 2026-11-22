<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClassProxyValueProvider;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ProvidedLabel
{
    public function __construct(public string $value = 'provided') {}
}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

abstract class Product
{
    public static int $constructions = 0;
    public string $label = 'declared';

    public function __construct()
    {
        ++self::$constructions;
        $this->label = 'constructed';
    }
}

#[Proxy, ProvidedLabel]
final class ProxyFirst extends Product {}

#[ProvidedLabel, Proxy]
final class ProviderFirst extends Product {}

final class ConflictingProperty
{
    #[Proxy, ProvidedLabel]
    public ProxyFirst $value;
}

final class LabelHandler implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof ProvidedLabel || !$context->entry instanceof Product) {
            throw new LogicException('Expected a class-level provider and an initialized product.');
        }
        ++$this->calls;
        $context->entry->label = $attribute->value;
    }
}

test('class Proxy defers a custom value provider without treating it as an injection-point conflict', function (bool $providerFirst, bool $uncached): void {
    $handler = new LabelHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ProvidedLabel::class,
            $handler,
            [ValueProvider::class],
            rules: $uncached ? [new NoOpRule()] : [],
        ))
        ->build();
    $class = $providerFirst ? ProviderFirst::class : ProxyFirst::class;
    $reflection = new ReflectionClass($class);
    Product::$constructions = 0;

    expect($container->has($class))->toBeTrue();
    $first = $container->get($class);
    Assert::assertInstanceOf($class, $first);
    expect($reflection->isUninitializedLazyObject($first))->toBeTrue()
        ->and(Product::$constructions)->toBe(0)
        ->and($handler->calls)->toBe(0)
        ->and($first->label)->toBe('provided')
        ->and(Product::$constructions)->toBe(1)
        ->and($handler->calls)->toBe(1)
        ->and($container->get($class))->toBe($first);

    $fresh = $container->make($class);
    Assert::assertInstanceOf($class, $fresh);
    expect($fresh)->not->toBe($first)
        ->and($reflection->isUninitializedLazyObject($fresh))->toBeTrue()
        ->and($fresh->label)->toBe('provided')
        ->and(Product::$constructions)->toBe(2)
        ->and($handler->calls)->toBe(2);
})->with(['Proxy first' => false, 'provider first' => true])
    ->with(['cached' => false, 'non-memoized' => true]);

test('the same custom provider still conflicts with Proxy on injection points before provider effects', function (bool $property): void {
    $handler = new LabelHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ProvidedLabel::class, $handler, [ValueProvider::class]))
        ->build();
    Product::$constructions = 0;
    $resolve = $property
        ? static fn(): object => $container->make(ConflictingProperty::class)
        : static fn(): mixed => $container->call(static fn(#[Proxy, ProvidedLabel] ProxyFirst $value): ProxyFirst => $value);

    expect($resolve)->toThrow(AttributeCompositionException::class, 'cannot be combined with value provider')
        ->and($handler->calls)->toBe(0)
        ->and(Product::$constructions)->toBe(0);
})->with(['property' => true, 'parameter' => false]);
