<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ResolutionInputIsolation;

use Attribute;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Internal\TransformHandler;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
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
