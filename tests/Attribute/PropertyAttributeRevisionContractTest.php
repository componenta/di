<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\PropertyAttributeRevisionContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Reflector;
use WeakReference;

final class Trace
{
    public int $constructors = 0;
    /** @var list<string> */
    public array $writes = [];
    /** @var list<string> */
    public array $handlers = [];
}

abstract class Product
{
    public string $value = 'initial';

    public function __construct(public Trace $trace)
    {
        ++$trace->constructors;
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class LateSuffix {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class LoadSuffix
{
    /** @var list<WeakReference<self>> */
    public static array $instances = [];

    public function __construct(string $alias)
    {
        self::$instances[] = WeakReference::create($this);
        if (!class_exists($alias, false)) {
            class_alias(LateSuffix::class, $alias);
        }
    }
}

final readonly class AppendSuffix implements AttributeHandlerInterface
{
    public function __construct(private Trace $trace, private string $suffix) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionProperty || !$context->propertyClaimed($target)) {
            throw new LogicException('The property source must run before its transformers.');
        }
        $value = $context->readProperty($target);
        if (!is_string($value)) {
            throw new LogicException('Expected the pending string supplied by the property source.');
        }
        $this->trace->handlers[] = $this->suffix;
        $context->writeProperty($target, $value . ':' . $this->suffix);
    }
}

final readonly class UncachedRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

test('property sources remain pending while a later attribute constructor reveals another transformer', function (
    string $strategy,
    bool $uncached,
): void {
    $suffix = bin2hex(random_bytes(6));
    $name = 'Product_' . $suffix;
    $alias = 'Suffix_' . $suffix;
    $policy = $strategy === 'eager' ? '' : '#[\\Componenta\\DI\\Attribute\\' . $strategy . ']';
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s final class %s extends Product
        {
            #[\Componenta\DI\Attribute\EntryId('input'), LoadSuffix(%s), %s]
            public string $value = 'initial' {
                set {
                    $this->trace->writes[] = $value;
                    $this->value = $value;
                }
            }
        }
        PHP,
        __NAMESPACE__,
        $policy,
        $name,
        var_export(__NAMESPACE__ . '\\' . $alias, true),
        $alias,
    ));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the property revision fixture.');
    }
    $trace = new Trace();
    LoadSuffix::$instances = [];
    $container = (new ContainerBuilder())
        ->addService(Trace::class, $trace)
        ->addService('input', 'input')
        ->addAttributeDefinition(new AttributeDefinition(
            LoadSuffix::class,
            new AppendSuffix($trace, 'loader'),
            [ValueTransformer::class],
            rules: $uncached ? [new UncachedRule()] : [],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            LateSuffix::class,
            new AppendSuffix($trace, 'suffix'),
            [ValueTransformer::class],
        ))
        ->build();

    expect($container->has($class))->toBeTrue()
        ->and(LoadSuffix::$instances)->toBe([]);

    for ($iteration = 1; $iteration <= 2; ++$iteration) {
        $product = $container->make($class);
        if (!$product instanceof Product) {
            throw new LogicException('Expected the constructed property revision fixture.');
        }
        expect(new ReflectionClass($class)->isUninitializedLazyObject($product))->toBe($strategy !== 'eager');
        expect($product->value)->toBe('input:loader:suffix')
            ->and($trace->constructors)->toBe($iteration)
            ->and($trace->writes)->toBe(array_fill(0, $iteration, 'input:loader:suffix'))
            ->and($trace->handlers)->toBe(array_merge(...array_fill(0, $iteration, ['loader', 'suffix'])))
            ->and(LoadSuffix::$instances)->toHaveCount($iteration)
            ->and(array_filter(LoadSuffix::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))->toBe([]);
    }
})->with(['eager', 'Lazy', 'Proxy'])->with(['cached' => false, 'uncached' => true]);
