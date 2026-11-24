<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\CompletedParameterClassificationContract;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use stdClass;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelSource {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelMetadata {}

abstract class Label
{
    public static int $constructions = 0;
    public string $value;

    public static function load(string $alias, string $attribute): void
    {
        if (!class_exists($alias, false)) {
            class_alias($attribute, $alias);
        }
    }
}

test('late attribute classification cannot publish a stale shared service', function (
    bool $queryIsAlias,
    bool $loadInConstructor,
    bool $related,
    int $priority,
): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Label_' . $suffix;
    $alias = __NAMESPACE__ . '\\Deferred_' . $suffix;
    $declared = $queryIsAlias ? LabelSource::class : $alias;
    $query = $queryIsAlias ? $alias : LabelSource::class;
    $source = $related ? LabelSource::class : LabelMetadata::class;
    $load = sprintf('self::load(%s, %s);', var_export($alias, true), var_export($source, true));
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class Label_%s extends Label {
            public function __construct(
                #[\%s] string $value = 'fallback',
                #[\Componenta\DI\Attribute\EntryId("loader")] ?object $loader = null,
            ) {
                ++Label::$constructions;
                $this->value = $value;
                %s
            }
        }
        PHP,
        __NAMESPACE__,
        $suffix,
        $declared,
        $loadInConstructor ? $load : '',
    ));
    Label::$constructions = 0;
    $resolverCalls = 0;
    $factoryCalls = 0;
    $container = (new ContainerBuilder())
        ->addParameterResolver(new AttributeMatchingParameterResolver(
            $query,
            static function (ParameterTarget $target, ParameterResolutionContext $context) use (&$resolverCalls): array {
                ++$resolverCalls;
                return [$target->position, 'from source'];
            },
        ), $priority)
        ->addFactory('loader', static function () use ($loadInConstructor, $alias, $source, &$factoryCalls): object {
            ++$factoryCalls;
            if (!$loadInConstructor) {
                Label::load($alias, $source);
            }
            return new stdClass();
        })->build();
    $get = static fn(): Label => fixtureInstance($container->get($class), Label::class);
    $changesSelection = $related && $priority > 200;

    if ($changesSelection) {
        expect($get)->toThrow(AttributeCompositionException::class, '$value')
            ->and(Label::$constructions)->toBe($loadInConstructor ? 1 : 0)
            ->and($resolverCalls)->toBe(0);
    }

    $result = $get();
    expect($result->value)->toBe($changesSelection ? 'from source' : 'fallback')
        ->and($get())->toBe($result)
        ->and(Label::$constructions)->toBe($changesSelection && $loadInConstructor ? 2 : 1)
        ->and($factoryCalls)->toBe(1)
        ->and($resolverCalls)->toBe($changesSelection ? 1 : 0);
})->with([
    'declaration revealed by a later dependency' => [false, false, true, 1000],
    'query alias revealed by a later dependency' => [true, false, true, 1000],
    'declaration revealed inside the constructor' => [false, true, true, 1000],
    'query alias revealed inside the constructor' => [true, true, true, 1000],
    'unrelated metadata' => [false, false, false, 1000],
    'unrelated query alias' => [true, true, false, 1000],
    'matching resolver after the selected default' => [false, false, true, 0],
]);

#[Attribute(Attribute::TARGET_CLASS)]
final class BeforePhaseCheck {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LastArgument {}

final class DeclareSelectorDuringValidation implements AttributeCompositionRuleInterface
{
    public bool $loadOnResolution = false;
    public bool $armed = false;

    public function __construct(private readonly string $name, private readonly string $source) {}

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($this->armed) {
            Label::load($this->name, $this->source);
        }
    }
}

test('constructor invocation rechecks a watched selector loaded while validating its before phase', function (
    bool $targeted,
    string $strategy,
    bool $related,
): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Product_' . $suffix;
    $observer = __NAMESPACE__ . '\\Observer_' . $suffix;
    $pending = __NAMESPACE__ . '\\Pending_' . $suffix;
    $selector = __NAMESPACE__ . '\\Selector_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[Pending_%s] final class Observer_%s {}
        %s
        #[BeforePhaseCheck]
        final class Product_%s extends Label {
            public function __construct(#[LabelSource] string $value = 'fallback', #[LastArgument] string $last = '') {
                ++Label::$constructions;
                $this->value = $value;
            }
        }
        PHP,
        __NAMESPACE__,
        $suffix,
        $suffix,
        $strategy === 'eager' ? '' : '#[\\Componenta\\DI\\Attribute\\' . $strategy . ']',
        $suffix,
    ));
    /** @var class-string<LabelSource|LabelMetadata> $selector The validation rule declares this alias. */
    $rule = new DeclareSelectorDuringValidation($selector, $related ? LabelSource::class : LabelMetadata::class);
    $dependencies = new DependencyDefinitions([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(BeforePhaseCheck::class, rules: [$rule])],
        ConfigKey::PARAMETER_RESOLVERS => [
            1000 => new AttributeMatchingParameterResolver(
                $selector,
                static fn(ParameterTarget $target): array => [$target->position, 'from source'],
            ),
            900 => new AttributeMatchingParameterResolver(
                LastArgument::class,
                static function (ParameterTarget $target) use ($rule, $pending): array {
                    if ($rule->loadOnResolution) {
                        // A new unrelated declaration makes the before-phase proof run again.
                        $rule->armed = true;
                        Label::load($pending, LabelMetadata::class);
                    }
                    return [$target->position, 'last'];
                },
            ),
        ],
    ]);
    $factory = new ContainerFactory();
    $config = new Config([], new Environment([]));
    $container = fixtureInstance(($targeted
        ? $factory->createWithTargetedAttributeDiscovery($config, $dependencies)
        : $factory->create($config, $dependencies))->container, Container::class);
    $container->make($observer);
    $warm = fixtureInstance($container->make($class), Label::class);
    expect(new \ReflectionClass($warm)->isUninitializedLazyObject($warm))->toBe($strategy !== 'eager')
        ->and($warm->value)->toBe('fallback');

    Label::$constructions = 0;
    $rule->loadOnResolution = true;
    $get = static fn(): Label => fixtureInstance($container->get($class), Label::class);
    if ($related) {
        expect(static fn(): string => $get()->value)->toThrow(AttributeCompositionException::class, 'classification for parameter $value')
            ->and(Label::$constructions)->toBe(0)
            ->and(class_exists($selector, false))->toBeTrue();
    }

    $entry = $get();
    expect($entry->value)->toBe($related ? 'from source' : 'fallback')
        ->and(Label::$constructions)->toBe(1)
        ->and(class_exists($selector, false))->toBeTrue()
        ->and($get())->toBe($entry);
})->with(['compatibility' => false, 'targeted' => true])
    ->with(['eager', 'Lazy', 'Proxy'])
    ->with(['new resolver selected' => true, 'unrelated alias' => false]);
