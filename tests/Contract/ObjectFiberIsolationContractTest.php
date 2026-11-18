<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectFiberIsolation;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use ReflectionProperty;
use Reflector;
use WeakReference;

final readonly class Payload
{
    public function __construct(public string $label) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class SuspendedValue
{
    public ?Payload $payload = null;
}

final class Product
{
    #[SuspendedValue]
    public Payload $payload;
}

final readonly class UncachedRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class SuspendedHandler implements AttributeHandlerInterface
{
    /** @var array<string, list<WeakReference<object>>> */
    public array $references = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $payload = $context->parameters['payload'] ?? null;
        if (!$attribute instanceof SuspendedValue || !$target instanceof ReflectionProperty
            || !$payload instanceof Payload || $context->entry === null
            || !$context->claimProperty($target)
        ) {
            throw new LogicException('Expected an isolated property creation attempt.');
        }

        $attribute->payload = $payload;
        $this->references[$payload->label] = array_map(
            WeakReference::create(...),
            [$attribute, $context, $context->entry, $payload],
        );
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend($payload->label);
        }
        $context->writeProperty($target, $attribute->payload);
    }
}

test('interleaved object handlers isolate their state and release a cancelled creation', function (bool $uncached): void {
    $handler = new SuspendedHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            SuspendedValue::class,
            $handler,
            [ValueProvider::class],
            rules: $uncached ? [new UncachedRule()] : [],
        ))
        ->build();
    $warm = $container->make(Product::class, ['payload' => new Payload('warm')]);
    expect($warm->payload->label)->toBe('warm');
    unset($warm);

    $first = new Fiber(static fn(): Product => $container->make(Product::class, ['payload' => new Payload('first')]));
    $second = new Fiber(static fn(): Product => $container->make(Product::class, ['payload' => new Payload('second')]));

    expect($first->start())->toBe('first')
        ->and($second->start())->toBe('second')
        ->and($handler->references['first'][0]->get())->not->toBe($handler->references['second'][0]->get());

    unset($first);
    gc_collect_cycles();
    expect(array_all($handler->references['first'], static fn(WeakReference $reference): bool => $reference->get() === null))
        ->toBeTrue()
        ->and($second->isSuspended())->toBeTrue();

    $second->resume();
    $result = $second->getReturn();
    if (!$result instanceof Product) {
        throw new LogicException('Expected the completed independent creation.');
    }
    expect($result->payload->label)->toBe('second');

    unset($second, $result);
    gc_collect_cycles();
    expect(array_all($handler->references['second'], static fn(WeakReference $reference): bool => $reference->get() === null))
        ->toBeTrue()
        ->and($container->make(Product::class, ['payload' => new Payload('next')])->payload->label)->toBe('next');
})->with(['cached plans' => false, 'uncached rules' => true]);
