<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\SelectiveAttributePlanCacheContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use ReflectionClass;
use Reflector;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Stamp {}

class Product
{
    public string $value = 'initial';
}

#[Stamp]
final class LoadedProduct extends Product {}

final class StampHandler implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
        if (!$attribute instanceof Stamp || !$context->entry instanceof Product) {
            throw new LogicException('Expected a prepared product.');
        }
        $context->entry->value = 'stamped';
    }
}

/** @return array{class-string<Product>,non-empty-string} */
function fixture(string $placement = 'class'): array
{
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'DeferredStamp_' . $suffix;
    $class = 'DeferredProduct_' . $suffix;
    $prefix = $placement === 'class' ? "#[$attribute] " : '';
    $member = match ($placement) {
        'property' => "#[$attribute] public ?string \$metadata = null;",
        'method' => "#[$attribute] public function run(): void {}",
        default => '',
    };
    eval(sprintf('namespace %s; %sfinal class %s extends Product { %s }', __NAMESPACE__, $prefix, $class, $member));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!is_subclass_of($name, Product::class)) {
        throw new LogicException('Expected the dynamic product fixture.');
    }
    return [$name, __NAMESPACE__ . '\\' . $attribute];
}

function pipeline(AttributePlanBuilder $plans, AttributeProcessor $processor): ObjectPipeline
{
    $parameters = new ParametersResolver($plans);
    $parameters->seal();
    return new ObjectPipeline($processor, $parameters, new ProxyFactory());
}

test('targeted discovery keeps a warm semantic plan when another target gains its handler', function (): void {
    [$subject, $lateName] = fixture();
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: true);
    $handler = new StampHandler();
    $registry->register(new AttributeDefinition(Stamp::class, $handler));
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $stable = new ReflectionClass(LoadedProduct::class);
    $late = new ReflectionClass($subject);

    $warm = $plans->build($stable);
    expect($plans->build($late)->attributes(Stamp::class))->toHaveCount(0);
    class_alias(Stamp::class, $lateName, false);

    expect($plans->build($late)->attributes(Stamp::class))->toHaveCount(1)
        ->and($plans->build($stable))->toBe($warm)
        ->and($handler->calls)->toBe(0);
});

test('cached object and handler discovery include a declared class or member attribute on the next creation', function (
    bool $targeted,
    string $placement,
): void {
    [$subject, $lateName] = fixture($placement);
    $registry = new AttributeDefinitionRegistry($targeted);
    $handler = new StampHandler();
    $registry->register(new AttributeDefinition(Stamp::class, $handler));
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $processor = new AttributeProcessor($plans);
    $pipeline = pipeline($plans, $processor);
    $target = new ReflectionClass($subject);

    expect($processor->hasHandlers($target))->toBeFalse()
        ->and(fixtureInstance($pipeline->create($subject), Product::class)->value)->toBe('initial');
    class_alias(Stamp::class, $lateName, false);

    expect($processor->hasHandlers($target))->toBeTrue()
        ->and(fixtureInstance($pipeline->create($subject), Product::class)->value)->toBe('stamped')
        ->and(fixtureInstance($pipeline->create($subject), Product::class)->value)->toBe('stamped')
        ->and($handler->calls)->toBe(2);
})->with(['compatible' => false, 'targeted' => true])->with(['class', 'property', 'method']);

test('new semantic definitions invalidate cached standalone plans even when the PHP class was already available', function (
    bool $targeted,
): void {
    $registry = new AttributeDefinitionRegistry($targeted);
    $plans = new AttributePlanBuilder($registry);
    $processor = new AttributeProcessor($plans);
    $pipeline = pipeline($plans, $processor);
    $target = new ReflectionClass(LoadedProduct::class);
    $handler = new StampHandler();

    expect($plans->build($target)->attributes(Stamp::class))->toHaveCount(0)
        ->and($processor->hasHandlers($target))->toBeFalse()
        ->and(fixtureInstance($pipeline->create(LoadedProduct::class), Product::class)->value)->toBe('initial');
    $registry->register(new AttributeDefinition(Stamp::class, $handler));

    expect($plans->build($target)->attributes(Stamp::class))->toHaveCount(1)
        ->and($processor->hasHandlers($target))->toBeTrue()
        ->and(fixtureInstance($pipeline->create(LoadedProduct::class), Product::class)->value)->toBe('stamped')
        ->and($handler->calls)->toBe(1);
})->with(['compatible' => false, 'targeted' => true]);

test('compatible discovery keeps loader failures observable while rebuilding known plans', function (): void {
    [$discovered, $lateName] = fixture();
    [$unrelated, $pendingName] = fixture();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Stamp::class));
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $pipeline = pipeline($plans, new AttributeProcessor($plans));
    $pipeline->prepare(LoadedProduct::class);
    $plans->build(new ReflectionClass($discovered));
    $plans->build(new ReflectionClass($unrelated));
    class_alias(Stamp::class, $lateName, false);

    $attempts = 0;
    $failure = new LogicException('The optional loader reported a failure.');
    $loader = static function (string $name) use ($pendingName, $failure, &$attempts): void {
        if ($name === $pendingName && ++$attempts === 3) {
            throw $failure;
        }
    };
    spl_autoload_register($loader, prepend: true);
    try {
        try {
            $pipeline->prepare(LoadedProduct::class);
            throw new LogicException('Expected the loader failure during preparation.');
        } catch (LogicException $exception) {
            expect($exception)->toBe($failure);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('the targeted after phase includes a member handler first declared during construction', function (): void {
    [$subject, $lateName] = fixture('property');
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: true);
    $handler = new StampHandler();
    $registry->register(new AttributeDefinition(Stamp::class, $handler));
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $processor = new AttributeProcessor($plans);
    $pipeline = pipeline($plans, $processor);
    $pipeline->prepare($subject);

    $value = $pipeline->create($subject, resolveParameters: static function () use ($lateName): array {
        class_alias(Stamp::class, $lateName, false);
        return [];
    });

    expect(fixtureInstance($value, Product::class)->value)->toBe('stamped')
        ->and($handler->calls)->toBe(1);
});

test('the targeted before phase rejects a new handler after its phase has completed', function (): void {
    [$subject, $lateName] = fixture();
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: true);
    $handler = new StampHandler();
    $registry->register(new AttributeDefinition(Stamp::class, $handler, phase: AttributePhase::BeforeInstantiation));
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $processor = new AttributeProcessor($plans);
    $pipeline = pipeline($plans, $processor);
    $pipeline->prepare($subject);

    expect(fn() => $pipeline->create($subject, resolveParameters: static function () use ($lateName): array {
        class_alias(Stamp::class, $lateName, false);
        return [];
    }))->toThrow(AttributeCompositionException::class, 'BeforeInstantiation completed')
        ->and($handler->calls)->toBe(0);
});
