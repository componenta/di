<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectCrossTargetPrefix;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final readonly class RecordTarget
{
    public function __construct(public string $label) {}
}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

abstract class Product
{
    public static int $constructions = 0;
    public string $value = 'declared';

    public function __construct()
    {
        ++self::$constructions;
        $this->value = 'constructed';
    }
}

final class DiscoverEarlierTarget implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly string $alias) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof RecordTarget) {
            throw new LogicException('Expected a target recording attribute.');
        }
        if (in_array($attribute->label, $this->events, true)) {
            throw new LogicException('A target handler must not be replayed in one creation attempt.');
        }
        $this->events[] = $attribute->label;
        if (!class_exists($this->alias, false)) {
            class_alias(RecordTarget::class, $this->alias);
        }
    }
}

test('a late earlier target cannot replace an executed prefix with the same attribute definition and declaration position', function (bool $before, bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'EarlierTarget_' . $suffix;
    $target = 'Product_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s("class")] final class %s extends Product { #[%s("property")] public string $marker = "declared"; }',
        __NAMESPACE__,
        $before ? $attribute : 'RecordTarget',
        $target,
        $before ? 'RecordTarget' : $attribute,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the cross-target prefix fixture.');
    }
    $handler = new DiscoverEarlierTarget(__NAMESPACE__ . '\\' . $attribute);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            RecordTarget::class,
            $handler,
            rules: $uncached ? [new NoOpRule()] : [],
            phase: $before ? AttributePhase::BeforeInstantiation : AttributePhase::AfterInstantiation,
        ))
        ->build();
    Product::$constructions = 0;

    expect($container->has($class))->toBeTrue()
        ->and($handler->events)->toBe([]);
    expect(fn(): object => $container->make($class))
        ->toThrow(AttributeCompositionException::class, 'already executed')
        ->and($handler->events)->toBe([$before ? 'property' : 'class'])
        ->and(Product::$constructions)->toBe($before ? 0 : 1);

    $handler->events = [];
    $entry = $container->make($class);
    expect($entry->value)->toBe('constructed')
        ->and($handler->events)->toBe($before ? ['class', 'property'] : ['property', 'class'])
        ->and(Product::$constructions)->toBe($before ? 1 : 2);
})->with(['before instantiation' => true, 'after instantiation' => false])
    ->with(['cached' => false, 'non-memoized' => true]);
