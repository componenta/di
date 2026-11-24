<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\BootstrapObservationStabilityContract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use ReflectionMethod;

use function Componenta\DI\Tests\Support\assembledContainer;
use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_CLASS)]
final class Marker {}

#[Attribute(Attribute::TARGET_ALL)]
final class StableMetadata {}

#[Attribute(Attribute::TARGET_CLASS)]
final class Extension {}

abstract class SeedState
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[Marker]
final class Seed extends SeedState {}

class Stable
{
    public function __construct(public string $value = 'stable') {}
}

#[StableMetadata]
final class StableClass extends Stable {}

final class StableProperty extends Stable
{
    #[StableMetadata]
    public string $metadata = 'unchanged';
}

final class StableMethod extends Stable
{
    #[StableMetadata]
    public function describe(): string
    {
        return $this->value;
    }
}

final class Observations
{
    public ?Container $container = null;
    public int $attempts = 0;
    public int $completed = 0;

    public function __construct(private readonly ParametersResolver $parameters) {}

    /** @param Closure(string=):string $same */
    public function resolve(string $kind, Closure $same): void
    {
        // This fixture limit only stops a broken proof from looping forever.
        // No exact count or production limit is part of the public contract.
        if (++$this->attempts > 256) {
            throw new LogicException('Fixture stopped repeated stable observations at 256.');
        }
        $di = $this->container ?? throw new LogicException('Expected the captured bootstrap container.');
        if ($kind === 'closure' || $kind === 'closure-invoke') {
            $value = $di->call($kind === 'closure' ? $same : [$same, '__invoke']);
        } elseif ($kind === 'reflection-resolve' || $kind === 'reflection-targets') {
            $parameters = $this->parameters;
            $reflections = (new ReflectionMethod($same, '__invoke'))->getParameters();
            $arguments = $kind === 'reflection-resolve'
                ? $parameters->resolve($reflections)
                : $parameters->resolveTargets(array_map($parameters->target(...), $reflections));
            // Native optional omission applies the closure's default at invocation.
            if ($arguments !== []) {
                throw new LogicException('Expected native omission for the closure method default.');
            }
            $value = $same(...$arguments);
        } else {
            $class = match ($kind) {
                'plain' => Stable::class,
                'class' => StableClass::class,
                'property' => StableProperty::class,
                'method' => StableMethod::class,
                default => throw new LogicException('Unexpected stable dependency fixture.'),
            };
            $value = fixtureInstance($di->make($class), $class)->value;
        }
        if ($value !== 'stable') {
            throw new LogicException('Repeated resolution changed the stable dependency value.');
        }
        ++$this->completed;
    }
}

final readonly class ResolvingRule implements AttributeCompositionRuleInterface
{
    /** @param Closure(string=):string $same */
    public function __construct(private Observations $observations, private string $kind, private Closure $same) {}

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        $this->observations->resolve($this->kind, $this->same);
    }
}

test('bootstrap proof converges when a composition rule repeatedly resolves the same stable dependency', function (string $kind): void {
    SeedState::$constructions = 0;
    $runtime = new ResolutionRuntime();
    $observations = new Observations($runtime->parameters);
    $same = static fn(string $value = 'stable'): string => $value;
    [$di] = assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(StableMetadata::class),
            static function (Container $di) use ($observations, $kind, $same): AttributeDefinition {
                $observations->container = $di;
                return new AttributeDefinition(Marker::class, rules: [new ResolvingRule($observations, $kind, $same)]);
            },
            static function (Container $di): AttributeDefinition {
                $di->get(Seed::class);
                return new AttributeDefinition(Extension::class);
            },
        ],
    ], runtime: $runtime);

    $cached = $di->get(Seed::class);
    expect($cached)->toBeInstanceOf(Seed::class)
        ->and($di->get(Seed::class))->toBe($cached)
        ->and(SeedState::$constructions)->toBe(1)
        ->and($observations->attempts)->toBeGreaterThan(0)
        ->and($observations->completed)->toBe($observations->attempts);

    // Completion must not disable the documented live user-rule behavior.
    $before = $observations->attempts;
    expect($di->make(Seed::class))->not->toBe($cached)
        ->and(SeedState::$constructions)->toBe(2)
        ->and($observations->attempts)->toBeGreaterThan($before)
        ->and($observations->completed)->toBe($observations->attempts);
})->with(['plain', 'class', 'property', 'method', 'closure', 'closure-invoke', 'reflection-resolve', 'reflection-targets']);

test('bootstrap proof converges when an unresolved attribute autoloader repeatedly resolves a stable dependency', function (string $kind): void {
    SeedState::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $pending = __NAMESPACE__ . '\\Pending_' . $suffix;
    $target = 'UnknownSeed_' . $suffix;
    eval(sprintf('namespace %s; #[Pending_%s] final class %s extends SeedState {}', __NAMESPACE__, $suffix, $target));
    /** @var class-string<SeedState> $class */
    $class = __NAMESPACE__ . '\\' . $target;
    $runtime = new ResolutionRuntime();
    $observations = new Observations($runtime->parameters);
    $same = static fn(string $value = 'stable'): string => $value;
    $loader = static function (string $requested) use ($pending, $observations, $kind, $same): void {
        if ($requested === $pending) {
            // A legitimate miss: no class or new attribute semantics appear.
            $observations->resolve($kind, $same);
        }
    };
    spl_autoload_register($loader);

    try {
        [$di] = assembledContainer([
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                new AttributeDefinition(StableMetadata::class),
                static function (Container $di) use ($observations, $class): AttributeDefinition {
                    $observations->container = $di;
                    $di->get($class);
                    return new AttributeDefinition(Extension::class);
                },
            ],
        ], runtime: $runtime);

        $cached = $di->get($class);
        expect($cached)->toBeInstanceOf(SeedState::class)
            ->and($di->get($class))->toBe($cached)
            ->and(SeedState::$constructions)->toBe(1)
            ->and(class_exists($pending, false))->toBeFalse()
            ->and($observations->attempts)->toBeGreaterThan(1)
            ->and($observations->completed)->toBe($observations->attempts);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['plain', 'closure', 'closure-invoke', 'reflection-resolve', 'reflection-targets']);
