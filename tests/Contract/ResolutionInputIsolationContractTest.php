<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ResolutionInputIsolation;

use Attribute;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\CallableInvoker;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Internal\TransformHandler;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixtures\ColdCallableInput;
use Componenta\DI\Tests\Fixtures\ColdConstructorInput;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionFunction;
use Reflector;
use stdClass;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_CLASS)]
final class ObserveArguments {}

#[ObserveArguments]
final readonly class Product
{
    /** @param array<array-key,mixed> $payload */
    public function __construct(public array $payload) {}
}

final readonly class ProductWithInputMetadata
{
    /** @param array{label: string} $nested */
    public function __construct(#[ColdConstructorInput] public string $label, public stdClass $owner, array $nested)
    {
        $nested['label'] = 'native constructor';
    }
}

final readonly class ConfiguredProduct
{
    public function __construct(public object $dependency, public string $label) {}
}

#[Lazy]
final class DeferredInputProduct
{
    public function __construct(public string $label) {}
}

/**
 * @param array{label: string} $nested
 * @return array{string, stdClass}
 */
function callWithInputMetadata(#[ColdCallableInput] string $label, stdClass $owner, array $nested): array
{
    $nested['label'] = 'native callable';
    return [$label, $owner];
}

final class MagicInputTarget
{
    /** @param array{label: string} $arguments */
    public function __call(string $name, array $arguments): string
    {
        $label = $arguments['label'];
        $arguments['label'] = 'native magic';
        return $label;
    }
}

/**
 * @param array<array-key,mixed> $payload
 * @return array<array-key,mixed>
 */
function updateLabel(array $payload): array
{
    $payload['label'] = 'formatted';
    return $payload;
}

define(__NAMESPACE__ . '\\UPDATE_LABEL', updateLabel(...));

final class ProductWithTransformedProperty
{
    /** @var array<array-key,mixed> */
    #[Transform(UPDATE_LABEL)]
    public array $payload;
}

final class ProductWithCastProperty
{
    /** @var array<array-key,mixed> */
    #[Cast('format-label')]
    public array $payload;
}

final class LabelCaster implements CasterInterface, CasterProviderInterface
{
    public string $name { get => 'format-label'; }

    public function provide(string $name): ?CasterInterface
    {
        return $name === $this->name ? $this : null;
    }

    /** @return array<array-key,mixed> */
    public function cast(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException('Expected a payload array.');
        }

        return updateLabel($value);
    }
}

/** @param array<array-key,mixed> $arguments */
function formatLocalCopy(array $arguments, bool $cycle): void
{
    $payload = $arguments['payload'] ?? null;
    if (!is_array($payload)) {
        throw new LogicException('Expected payload input.');
    }
    if ($cycle) {
        $loop = $payload['self'] ?? null;
        if (!is_array($loop)) {
            throw new LogicException('Expected the recursive payload.');
        }
        $loop['label'] = 'local formatting';
        $payload['self'] = $loop;
    } else {
        $payload['label'] = 'local formatting';
    }
    $arguments['payload'] = $payload;
}

/** @param array<array-key,mixed> $payload */
function observedLabel(array $payload, bool $cycle): mixed
{
    if (!$cycle) {
        return $payload['label'] ?? null;
    }
    $loop = $payload['self'] ?? null;
    if (!is_array($loop)) {
        throw new LogicException('Expected the recursive payload.');
    }
    return $loop['label'] ?? null;
}

test('editing an argument snapshot cannot change nested input for a later resolver or the caller', function (bool $constructor, bool $cycle): void {
    $container = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(ObserveArguments::class, new class ($cycle) implements AttributeHandlerInterface {
            public function __construct(private readonly bool $cycle) {}

            public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
            {
                formatLocalCopy($context->parameters, $this->cycle);
                if ($this->cycle && is_array($payload = $context->parameters['payload'] ?? null) && observedLabel($payload, true) !== 'original') {
                    throw new LogicException('A second read must return the original snapshot.');
                }
            }
        }, phase: AttributePhase::BeforeInstantiation))
        ->addParameterResolver(new class ($cycle) implements ParameterResolverInterface {
            public function __construct(private readonly bool $cycle) {}

            public function supports(ParameterTarget $target): bool
            {
                return $target->name === 'prefix';
            }

            public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
            {
                formatLocalCopy($context->provided, $this->cycle);
                return [$target->position, 'resolved'];
            }
        }, 10_000)
        ->build();
    $label = 'original';
    $identity = new stdClass();
    $payload = ['label' => &$label, 'identity' => $identity];
    if ($cycle) {
        $payload['self'] = &$payload;
    }

    $arguments = ['payload' => $payload];
    $result = $constructor
        ? $container->make(Product::class, $arguments)
        : $container->call(static fn(string $prefix, array $payload): Product => new Product($payload), $arguments);

    if (!$result instanceof Product) {
        throw new LogicException('Expected a product.');
    }
    expect(observedLabel($result->payload, $cycle))->toBe('original')
        ->and($label)->toBe('original')
        ->and($result->payload['identity'])->toBe($identity);
})->with(['make' => true, 'call' => false])
    ->with(['nested slot' => false, 'recursive array' => true]);

test('a callable receives literal nested references after argument selection', function (): void {
    $container = new ContainerBuilder()->build();
    $label = 'original';
    $arguments = ['payload' => ['label' => &$label]];

    $actual = $container->call(static function (array $payload): string {
        $payload['label'] = 'set by callable';
        return $payload['label'];
    }, $arguments);

    expect($actual)->toBe('set by callable')
        ->and($label)->toBe('set by callable');
});

test('a cold metadata lookup preserves the initial values of supplied argument slots', function (bool $constructor): void {
    $container = (new ContainerBuilder())->build();
    $attribute = $constructor ? ColdConstructorInput::class : ColdCallableInput::class;
    expect(class_exists($attribute, false))->toBeFalse();
    $label = 'original';
    $nested = 'original nested';
    $owner = new stdClass();
    $loads = 0;
    $load = static function (string $name) use ($attribute, &$label, &$loads): void {
        if ($name === $attribute) {
            ++$loads;
            $label = 'changed during autoload';
        }
    };
    $invoke = static function (array $arguments) use ($container, $constructor): mixed {
        if ($constructor) {
            $product = fixtureInstance($container->make(ProductWithInputMetadata::class, $arguments), ProductWithInputMetadata::class);
            return [$product->label, $product->owner];
        }
        return $container->call(__NAMESPACE__ . '\\callWithInputMetadata', $arguments);
    };
    $arguments = ['label' => &$label, 'owner' => $owner, 'nested' => ['label' => &$nested]];
    spl_autoload_register($load, prepend: true);
    try {
        $cold = $invoke($arguments);
        $afterLoad = $label;
        $afterInvocation = $nested;
        $label = 'original';
        $warm = $invoke($arguments);

        expect($cold)->toBe(['original', $owner])
            ->and($warm)->toBe($cold)
            ->and($loads)->toBe(1)
            ->and($afterLoad)->toBe('changed during autoload')
            ->and($afterInvocation)->toBe($constructor ? 'native constructor' : 'native callable');
    } finally {
        spl_autoload_unregister($load);
    }
})->with(['make' => true, 'call' => false]);

test('class definitions keep supplied slot values while resolving earlier configured dependencies', function (): void {
    $container = (new ContainerBuilder())->build();
    $dependency = __NAMESPACE__ . '\\ConfiguredDependency_' . bin2hex(random_bytes(5));
    $definition = ClassDefinition::create(ConfiguredProduct::class)->constructor([
        'dependency' => Definition::reference($dependency),
    ]);
    $container->set('configured.product', $definition);
    $label = 'original';
    $loads = 0;
    $load = static function (string $name) use ($dependency, &$loads, &$label): void {
        if ($name === $dependency) {
            ++$loads;
            $label = 'changed during dependency discovery';
            class_alias(MagicInputTarget::class, $dependency);
        }
    };
    $arguments = ['label' => &$label];
    spl_autoload_register($load);
    try {
        $cold = fixtureInstance($container->make('configured.product', $arguments), ConfiguredProduct::class);
        $afterLoad = $label;
        $label = 'original';
        $warm = fixtureInstance($container->make('configured.product', $arguments), ConfiguredProduct::class);

        expect($cold->label)->toBe('original')
            ->and($warm->label)->toBe($cold->label)
            ->and($cold->dependency)->toBe($container->get($dependency))
            ->and($warm->dependency)->toBe($cold->dependency)
            ->and($warm)->not->toBe($cold)
            ->and($loads)->toBe(1)
            ->and($afterLoad)->toBe('changed during dependency discovery');
    } finally {
        spl_autoload_unregister($load);
    }
});

test('the public parameter resolver selects supplied values before classifying arguments', function (bool $targets): void {
    $type = __NAMESPACE__ . '\\ParameterDependency_' . bin2hex(random_bytes(5));
    [, $runtime] = (new ContainerBuilder())->addParameterResolver(new class ($type) implements ParameterResolverInterface {
        public function __construct(private readonly string $type) {}

        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'label' && class_exists($this->type);
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
        {
            return null;
        }
    }, 10_000)->assemble();
    $reflection = new ReflectionFunction(static fn(string $label): string => $label);
    $parameters = $reflection->getParameters();
    $declaredTargets = $runtime->parameters->targets($parameters);
    $label = 'original';
    $loads = 0;
    $load = static function (string $name) use ($type, &$label, &$loads): void {
        if ($name === $type) {
            class_alias(MagicInputTarget::class, $type);
            $label = 'changed during classification';
            ++$loads;
        }
    };
    $invoke = static fn(array $arguments): array => $targets
        ? $runtime->parameters->resolveTargets($declaredTargets, $arguments)
        : $runtime->parameters->resolve($parameters, $arguments);
    $arguments = ['label' => &$label];

    spl_autoload_register($load);
    try {
        $cold = $invoke($arguments);
        $afterLoad = $label;
        $label = 'original';

        expect($cold)->toBe(['original'])
            ->and($invoke($arguments))->toBe($cold)
            ->and($loads)->toBe(1)
            ->and($afterLoad)->toBe('changed during classification');
    } finally {
        spl_autoload_unregister($load);
    }
})->with(['targets' => true, 'reflectors' => false]);

test('the object pipeline captures immediate values before metadata and runs a deferred supplier on demand', function (): void {
    [, $runtime] = (new ContainerBuilder())->assemble();
    /** @var class-string<ConfiguredProduct> $immediate */
    $immediate = __NAMESPACE__ . '\\ImmediateInput_' . bin2hex(random_bytes(5));
    /** @var class-string<DeferredInputProduct> $deferred */
    $deferred = __NAMESPACE__ . '\\DeferredInput_' . bin2hex(random_bytes(5));
    $label = 'original';
    $events = [];
    $load = static function (string $class) use ($immediate, $deferred, &$label, &$events): void {
        if ($class === $immediate) {
            class_alias(ConfiguredProduct::class, $immediate);
            $label = 'changed during metadata lookup';
            $events[] = 'immediate metadata';
        } elseif ($class === $deferred) {
            class_alias(DeferredInputProduct::class, $deferred);
            $events[] = 'deferred metadata';
        }
    };
    spl_autoload_register($load);
    try {
        $owner = new stdClass();
        $arguments = ['dependency' => $owner, 'label' => &$label];
        $cold = fixtureInstance($runtime->objects->create($immediate, $arguments), ConfiguredProduct::class);
        $afterLoad = $label;
        $label = 'original';
        $warm = fixtureInstance($runtime->objects->create($immediate, $arguments), ConfiguredProduct::class);
        expect($cold->label)->toBe('original')
            ->and($warm->label)->toBe($cold->label)
            ->and($cold->dependency)->toBe($owner)
            ->and($afterLoad)->toBe('changed during metadata lookup');

        $lazy = fixtureInstance($runtime->objects->create(
            $deferred,
            resolveParameters: static function () use (&$events, &$label): array {
                $events[] = 'supplier';
                return ['label' => $label];
            },
        ), DeferredInputProduct::class);
        expect($events)->toBe(['immediate metadata', 'deferred metadata']);
        $label = 'at initialization';
        expect($lazy->label)->toBe('at initialization')
            ->and($events)->toBe(['immediate metadata', 'deferred metadata', 'supplier']);
    } finally {
        spl_autoload_unregister($load);
    }
});

test('native and explicit factory argument passing retain their reference behavior', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('argument.factory', Definition::factory(static function (mixed $context, array $params): stdClass {
        $params['label'] = 'explicit factory';
        return new stdClass();
    }));
    $label = 'original';
    $arguments = ['label' => &$label];

    expect($container->call(new PreparedCallable(static fn(string $label): string => $label), $arguments))->toBe('original')
        ->and($label)->toBe('original')
        ->and($container->call([new MagicInputTarget(), 'dispatch'], $arguments))->toBe('original')
        ->and($label)->toBe('native magic');
    (new CallableInvoker())->call(static function (string &$label): void {
        $label = 'native invocation';
    }, $arguments);
    expect($label)->toBe('native invocation');
    expect($container->make('argument.factory', $arguments))->toBeInstanceOf(stdClass::class)
        ->and($label)->toBe('explicit factory');
});

test('a factory published while selecting the fallback receives the original reference-bearing array', function (): void {
    $container = (new ContainerBuilder())->build();
    $id = __NAMESPACE__ . '\\LateFactory_' . bin2hex(random_bytes(5));
    $label = 'original';
    $load = static function (string $name) use ($container, $id, &$label): void {
        if ($name === $id) {
            $container->set($id, Definition::factory(static function (mixed $context, array $params): Product {
                $before = $params['label'];
                $params['label'] = 'explicit factory';
                return new Product(['label' => $before]);
            }));
            $label = 'changed during fallback discovery';
        }
    };
    spl_autoload_register($load);
    try {
        $product = fixtureInstance($container->make($id, ['label' => &$label]), Product::class);
        expect($product->payload)->toBe(['label' => 'changed during fallback discovery'])
            ->and($label)->toBe('explicit factory');
    } finally {
        spl_autoload_unregister($load);
    }
});

test('a creation argument helper cannot rebind the literal later consumed by the constructor', function (bool $cycle): void {
    $observer = new class ($cycle) implements AttributeHandlerInterface {
        public function __construct(private readonly bool $cycle) {}

        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            $payload = null;
            if (!ObjectCreationAccess::tryReadParameter($context, 'payload', $payload)) {
                throw new LogicException('Expected an explicit constructor argument.');
            }
            formatLocalCopy(['payload' => $payload], $this->cycle);
        }
    };
    $container = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        ObserveArguments::class,
        $observer,
        phase: AttributePhase::BeforeInstantiation,
    ))->build();
    $label = 'original';
    $identity = new stdClass();
    $payload = ['label' => &$label, 'identity' => $identity];
    if ($cycle) {
        $payload['self'] = &$payload;
    }

    $entry = fixtureInstance($container->make(Product::class, ['payload' => $payload]), Product::class);
    expect(observedLabel($entry->payload, $cycle))->toBe('original')
        ->and($label)->toBe('original')
        ->and($entry->payload['identity'])->toBe($identity);
})->with(['nested slot' => false, 'recursive array' => true]);

#[ObserveArguments]
final class ManualProperty
{
    /** @var array<array-key,mixed> */
    public array $payload;
}

test('direct property handler calls use a snapshot of the supplied argument', function (bool $transform): void {
    $observer = new class () implements AttributeHandlerInterface {
        public AttributeHandlerInterface $manualHandler;
        public object $manualAttribute;

        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            $this->manualHandler->handle($this->manualAttribute, $context->class->getProperty('payload'), $context);
        }
    };
    [$container, $runtime] = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new LabelCaster())
        ->addAttributeDefinition(new AttributeDefinition(ObserveArguments::class, $observer))
        ->assemble();
    Assert::assertInstanceOf(ParametersResolver::class, $runtime->parameters);
    $observer->manualHandler = $transform
        ? new TransformHandler(new AttributeCallbackInvoker($runtime->parameters, $container))
        : new CastHandler($container);
    $observer->manualAttribute = $transform ? new Transform(UPDATE_LABEL) : new Cast('format-label');
    $label = 'original';
    $identity = new stdClass();

    $entry = fixtureInstance($container->make(ManualProperty::class, ['payload' => ['label' => &$label, 'identity' => $identity]]), ManualProperty::class);
    expect($entry->payload['label'])->toBe('formatted')
        ->and($label)->toBe('original')
        ->and($entry->payload['identity'])->toBe($identity);
})->with(['transform' => true, 'cast' => false]);

test('a property transform or caster receives literal nested references', function (bool $transform): void {
    $container = new ContainerBuilder()
        ->addService(CasterProviderInterface::class, new LabelCaster())
        ->build();
    $label = 'original';
    $class = $transform ? ProductWithTransformedProperty::class : ProductWithCastProperty::class;

    $result = fixtureInstance($container->make($class, ['payload' => ['label' => &$label]]), $class);

    expect($result->payload['label'])->toBe('formatted')
        ->and($label)->toBe('formatted');
})->with([
    'transform' => true,
    'cast' => false,
]);
