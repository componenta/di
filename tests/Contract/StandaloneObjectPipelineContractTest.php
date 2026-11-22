<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StandaloneObjectPipeline;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use Reflector;

final class Product
{
    /** @var list<string> */
    public array $events = [];

    public function __construct()
    {
        $this->events[] = 'constructor';
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class InspectParameters {}

#[InspectParameters]
final readonly class ParameterizedProduct
{
    public function __construct(public string $value) {}
}

test('standalone object pipelines construct and configure objects without optional bootstrap services', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $parameters = new ParametersResolver($plans);
    $parameters->seal();
    $pipeline = new ObjectPipeline(
        new AttributeProcessor($plans),
        $parameters,
        new ProxyFactory(),
    );

    foreach ([1, 2] as $_attempt) {
        $entry = $pipeline->create(Product::class, configure: static function (object $entry): void {
            if (!$entry instanceof Product) {
                throw new LogicException('Expected the standalone pipeline product.');
            }
            $entry->events[] = 'configure';
        });
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the configured pipeline product.');
        }

        expect($entry)->toBeInstanceOf(Product::class)
            ->and($entry->events)->toBe(['constructor', 'configure']);
    }
});

test('an object pipeline rejects a parameter resolver with a different attribute planner', function (): void {
    $processor = new AttributeProcessor(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $parameters = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));

    expect(fn() => new ObjectPipeline($processor, $parameters, new ProxyFactory()))
        ->toThrow(InvalidConfigurationException::class, 'same attribute plans');
});

test('a separate parameter store cannot replace the snapshot of an active object attempt', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $plans = new AttributePlanBuilder($registry);
    $parameters = new ParametersResolver($plans);
    $parameters->add(new ArrayResolver());
    $parameters->seal();
    $pipeline = new ObjectPipeline(new AttributeProcessor($plans), $parameters, new ProxyFactory());
    $store = new ObjectResolutionParameterStore();
    $observer = new class ($store) implements AttributeHandlerInterface {
        public mixed $observed = null;

        public function __construct(private readonly ObjectResolutionParameterStore $store) {}

        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            $this->store->attach($context, ['value' => 'replacement']);
            $this->observed = $context->parameters['value'];
        }
    };
    $registry->register(new AttributeDefinition(InspectParameters::class, $observer, phase: AttributePhase::BeforeInstantiation));

    expect($pipeline->create(ParameterizedProduct::class, ['value' => 'original']))->toEqual(new ParameterizedProduct('original'))
        ->and($observer->observed)->toBe('original');
});
