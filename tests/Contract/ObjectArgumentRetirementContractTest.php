<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectArgumentRetirement;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use Reflector;

use function Componenta\DI\Tests\Support\fixtureInstance;

abstract class Product
{
    /** @var list<string> */
    public static array $events = [];
    public bool $constructed = false;

    public function __construct()
    {
        self::$events[] = 'constructor';
        $this->constructed = true;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Note {}

#[Attribute(Attribute::TARGET_CLASS)]
final class AfterPhase {}

final readonly class Argument
{
    /** @param class-string|null $declaration */
    public function __construct(private string $label, private string $name = '', private ?string $declaration = null) {}

    public function __destruct()
    {
        Product::$events[] = $this->label;
        if ($this->declaration !== null) {
            class_alias($this->declaration, $this->name, autoload: false);
        }
    }
}

test('eager creation checks attributes revealed while releasing arguments owned by the pipeline', function (
    bool $targeted,
    bool $afterPhase,
    string $retirement,
): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Product_' . $suffix;
    $alias = __NAMESPACE__ . '\\Deferred_' . $suffix;
    eval(sprintf(
        'namespace %s; #[Deferred_%s] %s final class Product_%s extends Product {}',
        __NAMESPACE__,
        $suffix,
        $afterPhase ? '#[AfterPhase]' : '',
        $suffix,
    ));
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the object retirement fixture.');
    }
    $registry = new AttributeDefinitionRegistry($targeted);
    $registry->register(new AttributeDefinition(NoConstructor::class, new NoConstructorHandler(), phase: AttributePhase::BeforeInstantiation));
    $registry->register(new AttributeDefinition(AfterPhase::class, new class () implements AttributeHandlerInterface {
        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            Product::$events[] = 'after';
        }
    }));
    $plans = new AttributePlanBuilder($registry);
    $pipeline = new ObjectPipeline(new AttributeProcessor($plans), new ParametersResolver($plans), new ProxyFactory());
    $pipeline->prepare($class);

    Product::$events = [];
    $retained = null;
    $resolve = static function () use ($alias, $retirement, &$retained): array {
        Product::$events[] = 'resolve';
        $first = new Argument('first released');
        $second = new Argument('second released', $alias, $retirement === 'unrelated' ? Note::class : NoConstructor::class);
        if ($retirement === 'retained') {
            $retained = $second;
        }
        return ['first' => $first, 'second' => $second];
    };
    $make = static fn(): Product => fixtureInstance($pipeline->create(
        $class,
        configure: static function (): void {
            Product::$events[] = 'configure';
        },
        resolveParameters: $resolve,
    ), Product::class);
    $events = ['resolve', 'constructor', ...($afterPhase ? ['after'] : []), 'configure', 'first released'];
    if ($retirement === 'related') {
        expect($make)->toThrow(AttributeCompositionException::class, 'BeforeInstantiation');
    } else {
        expect($make()->constructed)->toBeTrue();
    }

    if ($retirement === 'retained') {
        expect(class_exists($alias, false))->toBeFalse()
            ->and(Product::$events)->toBe($events);
        $retained = null;
    }

    expect(class_exists($alias, false))->toBeTrue()
        ->and(Product::$events)->toBe([...$events, 'second released'])
        ->and(fixtureInstance($pipeline->create($class), Product::class)->constructed)->toBe($retirement === 'unrelated');
})->with(['compatibility' => false, 'targeted' => true])
    ->with(['no active handlers' => false, 'with after-phase handler' => true])
    ->with(['related', 'unrelated', 'retained']);
