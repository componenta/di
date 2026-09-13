<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;
use stdClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class CreationContextExtension {}

#[CreationContextExtension]
final class CreationContextProduct
{
    public function __construct(public int $value) {}
}

final readonly class CreationContextObserver implements AttributeHandlerInterface
{
    /** @param Closure(ObjectCreationContext):void $observe */
    public function __construct(private Closure $observe) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ($this->observe)($context);
    }
}

test('object handlers materialize public parameters once and share them with the constructor', function (bool $deferred): void {
    $observed = [];
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use (&$observed): void {
        $observed = [$context->constructorEnabled, $context->parameters, $context->parameters];
    });
    $pipeline = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build()->get(ObjectPipeline::class);
    $calls = 0;
    $source = static function () use (&$calls): array {
        ++$calls;
        return ['value' => 7];
    };

    $entry = $deferred
        ? $pipeline->create(CreationContextProduct::class, resolveParameters: $source)
        : $pipeline->create(CreationContextProduct::class, ['value' => 7]);

    expect($entry)->toEqual(new CreationContextProduct(7))
        ->and($observed)->toBe([true, ['value' => 7], ['value' => 7]])
        ->and($calls)->toBe($deferred ? 1 : 0);
})->with([true, false]);

test('object handlers may select the same creation strategy repeatedly', function (CreationStrategy $strategy): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($strategy): void {
        $context->selectStrategy($strategy);
        $context->selectStrategy($strategy);
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();

    expect($container->make(CreationContextProduct::class, ['value' => 9])->value)->toBe(9);
})->with(CreationStrategy::cases());

test('a creation strategy cannot change after a handler selects deferred construction', function (CreationStrategy $first, CreationStrategy $second): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context) use ($first, $second): void {
        $context->selectStrategy($first);
        $context->selectStrategy($second);
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();

    expect(fn() => $container->make(CreationContextProduct::class, ['value' => 9]))
        ->toThrow(InvalidConfigurationException::class, sprintf(
            'Creation strategies "%s" and "%s" cannot be combined for "%s".',
            $first->name,
            $second->name,
            CreationContextProduct::class,
        ));
})->with([
    [CreationStrategy::Lazy, CreationStrategy::Eager],
    [CreationStrategy::Proxy, CreationStrategy::Eager],
    [CreationStrategy::Lazy, CreationStrategy::Proxy],
    [CreationStrategy::Proxy, CreationStrategy::Lazy],
]);

test('a custom handler cannot initialize a creation context with an unrelated object', function (): void {
    $handler = new CreationContextObserver(static function (ObjectCreationContext $context): void {
        $context->initialize(new stdClass());
    });
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CreationContextExtension::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build();

    try {
        $container->make(CreationContextProduct::class, ['value' => 9]);
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBeInstanceOf(LogicException::class)
            ->and($error->getPrevious()?->getMessage())
            ->toBe(sprintf('Expected an instance of "%s", got "stdClass".', CreationContextProduct::class));
        return;
    }
    throw new LogicException('An unrelated initialization should fail.');
});
