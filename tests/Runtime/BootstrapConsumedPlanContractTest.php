<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\BootstrapConsumedPlanContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;

final class State
{
    public bool $awaitingConfiguration = false;
    /** @var list<string> */
    public array $events = [];
    public ?Product $entry = null;
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Marker {}

#[Attribute(Attribute::TARGET_CLASS)]
final class Extra {}

#[Marker]
final class Product
{
    public bool $configured = false;

    public function __construct(State $state)
    {
        $state->events[] = 'constructor';
    }

    public function configure(State $state): void
    {
        $state->events[] = 'configure';
        $this->configured = true;
        $state->awaitingConfiguration = false;
    }
}

final readonly class Rule implements AttributeCompositionRuleInterface
{
    public function __construct(private State $state) {}

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($this->state->awaitingConfiguration) {
            throw new AttributeCompositionException('Completed phase was revalidated before configuration.');
        }
    }
}

final readonly class Handler implements AttributeHandlerInterface
{
    public function __construct(private State $state) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->state->events[] = 'handler';
        $this->state->awaitingConfiguration = true;
    }
}

test('bootstrap records consumed metadata before a delegator configures the completed entry', function (): void {
    $state = new State();
    $container = (new ContainerBuilder())
        ->addService(State::class, $state)
        ->addDelegator(Product::class, static function (Product $entry) use ($state): Product {
            $entry->configure($state);
            return $entry;
        })
        ->addAttributeDefinition(new AttributeDefinition(
            Marker::class,
            new Handler($state),
            phase: AttributePhase::AfterInstantiation,
            rules: [new Rule($state)],
        ))
        ->addAttributeDefinition(static function (Container $container) use ($state): AttributeDefinition {
            $entry = $container->get(Product::class);
            if (!$entry instanceof Product) {
                throw new LogicException('Expected the configured product.');
            }
            $state->entry = $entry;
            return new AttributeDefinition(Extra::class);
        })
        ->build();

    $entry = $state->entry;
    if (!$entry instanceof Product) {
        throw new LogicException('Expected the bootstrap product.');
    }
    expect($state->events)->toBe(['constructor', 'handler', 'configure'])
        ->and($entry->configured)->toBeTrue()
        ->and($state->awaitingConfiguration)->toBeFalse()
        ->and($container->get(Product::class))->toBe($entry);
});
