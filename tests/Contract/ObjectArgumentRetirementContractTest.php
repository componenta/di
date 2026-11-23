<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectArgumentRetirement;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryParametersFactoryInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
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

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Label {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class OtherLabel {}

abstract class ClassifiedProduct
{
    public string $label;
}

test('eager creation rechecks a resolver observation after releasing its supplied arguments', function (bool $targeted, bool $queryAlias, bool $related): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Classified_' . $suffix;
    $observer = __NAMESPACE__ . '\\Observer_' . $suffix;
    $pending = __NAMESPACE__ . '\\Pending_' . $suffix;
    $alias = __NAMESPACE__ . '\\Alias_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[Pending_%s] final class Observer_%s {}
        final class Classified_%s extends ClassifiedProduct {
            public function __construct(#[%s] string $label = 'fallback') {
                $this->label = $label;
                Product::$events[] = $label;
            }
        }
        PHP,
        __NAMESPACE__,
        $suffix,
        $suffix,
        $suffix,
        $queryAlias ? 'Label' : 'Alias_' . $suffix,
    ));
    if (!is_a($class, ClassifiedProduct::class, true) || !class_exists($observer, false)) {
        throw new LogicException('Expected the parameter retirement fixtures.');
    }
    $plans = new AttributePlanBuilder(new AttributeDefinitionRegistry($targeted));
    $parameters = new ParametersResolver($plans);
    $parameters->add(new AttributeMatchingParameterResolver(
        $queryAlias ? $alias : Label::class,
        static fn(ParameterTarget $target): array => [$target->position, 'selected'],
    ), 100);
    $parameters->add(new AttributeParameterResolver($plans), 50);
    $parameters->add(new DefaultValueResolver());
    $parameters->seal();
    $pipeline = new ObjectPipeline(new AttributeProcessor($plans), $parameters, new ProxyFactory());
    $pipeline->prepare($observer);
    expect(fixtureInstance($pipeline->create($class), ClassifiedProduct::class)->label)->toBe('fallback');

    $unrelatedLoads = new class () {
        public int $count = 0;
    };
    $loader = static function (string $name) use ($alias, $pending, $unrelatedLoads): void {
        if ($name === $pending && class_exists($alias, false)) {
            ++$unrelatedLoads->count;
        }
    };
    Product::$events = [];
    spl_autoload_register($loader, prepend: true);
    try {
        $make = static fn(): ClassifiedProduct => fixtureInstance($pipeline->create(
            $class,
            resolveParameters: static fn(): array => ['owned' => new Argument('released', $alias, $related ? Label::class : OtherLabel::class)],
        ), ClassifiedProduct::class);
        if ($related) {
            expect($make)->toThrow(AttributeCompositionException::class, 'classification for parameter $label');
        } else {
            expect($make()->label)->toBe('fallback');
        }
        expect(Product::$events)->toBe(['fallback', 'released'])
            ->and($unrelatedLoads->count)->toBe(0)
            ->and(fixtureInstance($pipeline->create($class), ClassifiedProduct::class)->label)->toBe($related ? 'selected' : 'fallback');
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['compatibility' => false, 'targeted' => true])
    ->with(['watched query' => true, 'declared alias' => false])
    ->with(['matching' => true, 'unrelated' => false]);

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ExplicitSource implements ParameterSourceAttributeInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class RetirementMapping extends MapQueryString
{
    private ?Argument $owned = null;

    /** @param class-string $declaration */
    public function __construct(private readonly string $alias, private readonly string $declaration, private readonly bool $retainInAttribute = false)
    {
        parent::__construct();
    }

    #[\Override]
    public function transform(array $data): array
    {
        $argument = new Argument('released mapped input', $this->alias, $this->declaration);
        if ($this->retainInAttribute) {
            $this->owned = $argument;
        }
        return [...parent::transform($data), 'owned' => $this->owned ?? $argument];
    }
}

final readonly class RetirementMappingAdapter implements ParameterAttributeHandlerInterface
{
    public function __construct(private RequestAttributeHandler $mapping, private string $mode) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        $input = match ($this->mode) {
            'clone-context' => clone $context,
            'copy-context' => new ParameterResolutionContext($context->provided, $context->resolved),
            default => $context,
        };
        $mapped = $this->mapping->resolveParameter($attribute, $target, $input, $plan, $value);
        return $this->mode === 'forward' ? $mapped : ParameterAttributeValue::resolved($mapped->value);
    }
}

final readonly class StagedInput
{
    public function __construct(public EntryParameters $parameters) {}
}

test('eager return cannot publish a mapped constructor argument whose source was revealed by input retirement', function (bool $targeted, bool $related): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Mapped_' . $suffix;
    $alias = __NAMESPACE__ . '\\Source_' . $suffix;
    $stage = eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class Mapped_%s extends ClassifiedProduct {
            public function __construct(#[Source_%s] string $label) {
                $this->label = $label;
                Product::$events[] = $label;
            }
        }
        return static fn(#[RetirementMapping(%s, %s)] StagedInput $input): StagedInput => $input;
        PHP,
        __NAMESPACE__,
        $suffix,
        $suffix,
        var_export($alias, true),
        var_export($related ? ExplicitSource::class : OtherLabel::class, true),
    ));
    if (!$stage instanceof Closure || !is_a($class, ClassifiedProduct::class, true)) {
        throw new LogicException('Expected the mapped retirement fixtures.');
    }
    $capture = new class () implements EntryParametersFactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            throw new LogicException('Expected a forwarded mapped parameter bag.');
        }

        public function makeEntry(string $entry, EntryParameters $parameters): object
        {
            return new StagedInput($parameters);
        }
    };
    $mapping = new ContainerBuilder()->addAttributeDefinition(new AttributeDefinition(
        RetirementMapping::class,
        new RequestAttributeHandler($capture, new TestCasterProvider()),
        [ValueProvider::class],
    ))->build();
    $request = new ServerRequest('GET', '/')->withQueryParams(['label' => 'mapped']);
    $plans = new AttributePlanBuilder(new AttributeDefinitionRegistry($targeted));
    $parameters = new ParametersResolver($plans);
    $parameters->add(new ArrayResolver());
    $parameters->seal();
    $pipeline = new ObjectPipeline(new AttributeProcessor($plans), $parameters, new ProxyFactory());
    $resolve = static fn(): EntryParameters => fixtureInstance(
        $mapping->call($stage, [ServerRequestInterface::class => $request]),
        StagedInput::class,
    )->parameters;
    $make = static fn(): ClassifiedProduct => fixtureInstance($pipeline->create($class, resolveParameters: $resolve), ClassifiedProduct::class);

    Product::$events = [];
    if ($related) {
        expect($make)->toThrow(RequestParameterSourceConflictException::class, 'label');
    } else {
        expect($make()->label)->toBe('mapped');
    }
    expect(Product::$events)->toBe(['mapped', 'released mapped input']);
})->with(['compatibility' => false, 'targeted' => true])
    ->with(['explicit source' => true, 'unrelated' => false]);

test('request mapping checks consumed DTO sources after releasing the mapped input', function (
    bool $targeted,
    bool $setup,
    bool $related,
    bool $attributeOwned,
    string $mode,
): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = __NAMESPACE__ . '\\Source_' . $suffix;
    $read = eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s final class Dto_%s extends ClassifiedProduct {
            public function %s(#[Source_%s] string $label) {
                $this->label = $label;
                Product::$events[] = $label;
            }
        }
        return static function (#[RetirementMapping(%s, %s, %s)] Dto_%s $dto): string {
            Product::$events[] = 'body';
            return $dto->label;
        };
        PHP,
        __NAMESPACE__,
        $setup ? '#[\\Componenta\\DI\\Attribute\\SetUp("apply")]' : '',
        $suffix,
        $setup ? 'apply' : '__construct',
        $suffix,
        var_export($alias, true),
        var_export($related ? ExplicitSource::class : OtherLabel::class, true),
        var_export($attributeOwned, true),
        $suffix,
    ));
    if (!$read instanceof Closure) {
        throw new LogicException('Expected the mapped DTO callable.');
    }
    $dependencies = new DependencyDefinitions([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (ContainerInterface $container) use ($mode): AttributeDefinition {
                $mapper = fixtureInstance($container->get(RequestAttributeHandler::class), RequestAttributeHandler::class);
                $handler = $mode === 'stock' ? $mapper : new RetirementMappingAdapter($mapper, $mode);
                return new AttributeDefinition(RetirementMapping::class, $handler, [ValueProvider::class]);
            },
        ],
    ]);
    $factory = new ContainerFactory();
    $config = new Config([], new Environment([]));
    $container = fixtureInstance(($targeted
        ? $factory->createWithTargetedAttributeDiscovery($config, $dependencies)
        : $factory->create($config, $dependencies))->container, Container::class);
    $request = new ServerRequest('GET', '/')->withQueryParams(['label' => 'mapped']);
    $invoke = static fn(): mixed => $container->call($read, [ServerRequestInterface::class => $request]);

    Product::$events = [];
    if ($related) {
        expect($invoke)->toThrow(RequestParameterSourceConflictException::class, 'label')
            ->and(Product::$events)->toBe(['mapped', 'released mapped input']);
    } else {
        expect($invoke())->toBe('mapped')
            ->and(Product::$events)->toBe(['mapped', 'released mapped input', 'body']);
    }
})->with(['compatibility' => false, 'targeted' => true])
    ->with(['constructor' => false, 'SetUp method' => true])
    ->with(['explicit source' => true, 'unrelated' => false])
    ->with(['mapping frame owns input' => false, 'attribute also owns input' => true])
    ->with([
        'stock mapper' => 'stock',
        'forwarding adapter' => 'forward',
        'adapter with fresh result state' => 'rewrap',
        'adapter with a cloned context' => 'clone-context',
        'adapter with a copied context' => 'copy-context',
    ]);
