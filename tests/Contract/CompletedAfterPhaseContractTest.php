<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompletedAfterPhase;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute]
final class NeutralMetadata {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

#[NeutralMetadata]
final class RegisteredMetadataProduct
{
    #[NeutralMetadata]
    public string $value = 'default';

    #[NeutralMetadata]
    public function marker(): void {}
}

abstract class State
{
    public static int $constructors = 0;
    public static int $knownCalls = 0;
    public static int $lateCalls = 0;
    public string $value = 'default';

    public function __construct()
    {
        ++self::$constructors;
    }

    public function known(): void
    {
        ++self::$knownCalls;
    }

    public function late(): void
    {
        ++self::$lateCalls;
        $this->value = 'ready';
    }
}

test('configuration cannot silently change a completed after phase or retain failed lazy attempt metadata', function (
    string $strategy,
    bool $uncached,
): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'LateSetup_' . $suffix;
    $name = 'ConfiguredProduct_' . $suffix;
    $known = $strategy === 'eager' ? '#[\\Componenta\\DI\\Attribute\\SetUp("known")]' : '';
    $deferred = in_array($strategy, ['Lazy', 'Proxy'], true);
    $policy = $deferred ? '#[\\Componenta\\DI\\Attribute\\' . $strategy . ']' : '';
    eval(sprintf('namespace %s; %s %s #[%s("late")] final class %s extends State {}', __NAMESPACE__, $known, $policy, $alias, $name));
    $class = __NAMESPACE__ . '\\' . $name;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    if (!is_a($class, State::class, true)) {
        throw new \LogicException('Expected the object-phase fixture.');
    }
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(NeutralMetadata::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $pipeline = $container->get(ObjectPipeline::class);
    $factories = 0;
    $configures = 0;
    State::$constructors = State::$knownCalls = State::$lateCalls = 0;
    $container->set('phase-product', new FactoryDefinition(static function () use ($pipeline, $class, $fullAlias, &$factories, &$configures): object {
        ++$factories;
        return $pipeline->create($class, configure: static function (object $entry) use ($fullAlias, &$configures): void {
            ++$configures;
            if (!class_exists($fullAlias, false)) {
                class_alias(SetUp::class, $fullAlias);
            }
        });
    }));

    $pending = $deferred ? $container->get('phase-product') : null;
    if ($deferred && !$pending instanceof State) {
        throw new \LogicException('Expected a deferred object-phase fixture.');
    }
    $firstAttempt = $pending instanceof State
        ? static fn(): string => $pending->value
        : static fn(): mixed => $container->get('phase-product');

    expect($firstAttempt)->toThrow(AttributeCompositionException::class, 'AfterInstantiation completed')
        ->and(State::$constructors)->toBe(1)
        ->and(State::$knownCalls)->toBe($strategy === 'eager' ? 1 : 0)
        ->and(State::$lateCalls)->toBe(0)
        ->and($configures)->toBe(1)
        ->and($factories)->toBe(1);

    $resolved = $pending ?? $container->get('phase-product');
    if (!$resolved instanceof State) {
        throw new \LogicException('Expected the retried object-phase fixture.');
    }
    expect($resolved->value)->toBe('ready')
        ->and($container->get('phase-product'))->toBe($resolved)
        ->and(State::$constructors)->toBe(2)
        ->and(State::$knownCalls)->toBe($strategy === 'eager' ? 2 : 0)
        ->and(State::$lateCalls)->toBe(1)
        ->and($configures)->toBe(2)
        ->and($factories)->toBe($deferred ? 1 : 2);
})->with(['no-handler fast path', 'eager', 'Lazy', 'Proxy'])
    ->with(['cached' => false, 'non-memoized' => true]);

test('configuration may load unrelated metadata without rejecting a completed object phase', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'LateNeutral_' . $suffix;
    $name = 'NeutralProduct_' . $suffix;
    eval(sprintf('namespace %s; #[%s] final class %s extends State {}', __NAMESPACE__, $alias, $name));
    $class = __NAMESPACE__ . '\\' . $name;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    if (!is_a($class, State::class, true)) {
        throw new \LogicException('Expected the neutral metadata fixture.');
    }
    $pipeline = (new ContainerBuilder())->build()->get(ObjectPipeline::class);
    $configures = 0;
    State::$constructors = 0;

    $entry = $pipeline->create($class, configure: static function (object $entry) use ($fullAlias, &$configures): void {
        ++$configures;
        class_alias(NeutralMetadata::class, $fullAlias);
    });

    expect($entry)->toBeInstanceOf(State::class)
        ->and(State::$constructors)->toBe(1)
        ->and($configures)->toBe(1);
});

test('handlerless after phases retain validated metadata on every object target', function (bool $uncached): void {
    $pipeline = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(NeutralMetadata::class, rules: $uncached ? [new NoOpRule()] : []))
        ->build()
        ->get(ObjectPipeline::class);

    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $entry = $pipeline->create(RegisteredMetadataProduct::class, configure: static function (object $entry): void {
            if (!$entry instanceof RegisteredMetadataProduct) {
                throw new \LogicException('Expected the registered metadata fixture.');
            }
            $entry->value = 'configured';
        });
        if (!$entry instanceof RegisteredMetadataProduct) {
            throw new \LogicException('Expected the configured metadata fixture.');
        }

        expect($entry->value)->toBe('configured');
    }
})->with(['cached' => false, 'non-memoized' => true]);
