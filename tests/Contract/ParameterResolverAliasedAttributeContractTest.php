<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ParameterResolverAliasedAttribute;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargets;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use DomainException;
use Fiber;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionAttribute;
use ReflectionParameter;
use WeakReference;

use function Componenta\DI\Tests\Support\container;
use function Componenta\DI\Tests\Support\fixtureInstance;

interface LabelKind {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Label implements LabelKind {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class OtherLabel {}

final readonly class Product
{
    public function __construct(#[Label] public string $label = 'default') {}
}

function render(#[Label] string $label = 'default'): string
{
    return $label;
}

/** @return class-string<LabelKind|OtherLabel> */
function nextAlias(): string
{
    /** @var class-string<LabelKind|OtherLabel> $alias The fixture declares this alias during the test. */
    $alias = __NAMESPACE__ . '\\Query_' . bin2hex(random_bytes(5));
    return $alias;
}

final class LabelResolver implements ParameterResolverInterface
{
    public int $classifications = 0;

    /** @param class-string $attribute */
    public function __construct(
        private readonly string $attribute,
        private readonly string $lookup = 'presence',
        private readonly bool $invert = false,
        private readonly bool $pauseOnMiss = false,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        ++$this->classifications;
        $found = match ($this->lookup) {
            'instance' => $target->firstAttribute($this->attribute) !== null,
            'native' => $this->hasNativeAttribute($target),
            default => $target->hasAttribute($this->attribute),
        };
        if (!$found && $this->pauseOnMiss && Fiber::getCurrent() !== null) {
            Fiber::suspend('classification paused');
        }
        return $this->invert ? !$found : $found;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, 'attribute'];
    }

    private function hasNativeAttribute(ParameterTarget $target): bool
    {
        $target->watchAttributeAvailability($this->attribute);
        return (class_exists($this->attribute, false) || interface_exists($this->attribute, false))
            && $target->reflection->getAttributes($this->attribute, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }
}

final readonly class OptionalTypeResolver implements ParameterResolverInterface
{
    public function __construct(private string $type, private ?string $parameter = null) {}

    public function supports(ParameterTarget $target): bool
    {
        return ($this->parameter === null || $this->parameter === $target->name) && class_exists($this->type);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

final readonly class Trigger
{
    public function __construct(public string $probe = 'ready') {}
}

final class SuspendingLabelResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Label::class);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend('value paused');
        }
        return [$target->position, 'original'];
    }
}

test('a warm classification observes aliases consulted by parameter attribute lookup', function (
    bool $targeted,
    string $lookup,
    ?bool $matches,
): void {
    $query = nextAlias();
    $resolver = new LabelResolver($query, $lookup);
    $factory = new ContainerFactory();
    $config = new Config([], new Environment([]));
    $definitions = new DependencyDefinitions([ConfigKey::PARAMETER_RESOLVERS => [1000 => $resolver]]);
    $build = static function () use ($targeted, $factory, $config, $definitions): Container {
        $di = ($targeted
            ? $factory->createWithTargetedAttributeDiscovery($config, $definitions)
            : $factory->create($config, $definitions))->container;
        return $di instanceof Container ? $di : throw new LogicException('Expected the DI container.');
    };
    $di = $build();
    $named = __NAMESPACE__ . '\\render';
    $closure = static fn(#[Label] string $label = 'default'): string => $label;

    $autoloads = 0;
    $loader = static function (string $name) use ($query, &$autoloads): void {
        if ($name === $query) {
            ++$autoloads;
        }
    };
    spl_autoload_register($loader);
    try {
        expect(fixtureInstance($di->make(Product::class), Product::class)->label)->toBe('default')
            ->and($di->call($named))->toBe('default')
            ->and($di->call($closure))->toBe('default');
        $before = $resolver->classifications;
        expect(fixtureInstance($di->make(Product::class), Product::class)->label)->toBe('default')
            ->and($di->call($named))->toBe('default')
            ->and($resolver->classifications)->toBe($before);

        if ($matches !== null) {
            class_alias($matches ? Label::class : OtherLabel::class, $query);
        }
        $value = $matches === true ? 'attribute' : 'default';
        expect(fixtureInstance($di->make(Product::class), Product::class)->label)->toBe($value)
            ->and($di->call($named))->toBe($value)
            ->and($di->call($closure))->toBe($value);
        $fresh = $build();
        expect(fixtureInstance($fresh->make(Product::class), Product::class)->label)->toBe($value)
            ->and($fresh->call($named))->toBe($value)
            ->and($autoloads)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['global discovery' => false, 'targeted discovery' => true])
    ->with(['presence lookup' => 'presence', 'instance lookup' => 'instance', 'native reflection' => 'native'])
    ->with(['matching alias' => true, 'unrelated alias' => false, 'unavailable alias' => [null]]);

test('native reflection can watch an interface alias', function (): void {
    $query = nextAlias();
    $di = container([ConfigKey::PARAMETER_RESOLVERS => [1000 => new LabelResolver($query, 'native')]]);
    $named = __NAMESPACE__ . '\\render';
    expect($di->call($named))->toBe('default');

    class_alias(LabelKind::class, $query);

    expect($di->call($named))->toBe('attribute');
});

test('an alias loaded by a later classifier is reflected in the same parameter selection', function (string $lookup): void {
    $query = nextAlias();
    $type = $query . 'Trigger';
    [$di, $runtime] = \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::PARAMETER_RESOLVERS => [1000 => new LabelResolver($query, $lookup), 900 => new OptionalTypeResolver($type)],
    ]);
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $target = $parameters->target(new ReflectionParameter(__NAMESPACE__ . '\\render', 0));
    $loader = static function (string $class) use ($query, $type): void {
        if ($class === $type) {
            class_alias(Label::class, $query);
            class_alias(OtherLabel::class, $type);
        }
    };
    spl_autoload_register($loader);
    try {
        expect($parameters->resolveTargets([$target]))->toBe(['attribute']);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['presence lookup' => 'presence', 'native reflection' => 'native']);

test('bootstrap rejects a changed attribute lookup for a parameter already consumed', function (bool $matches, bool $invert, string $lookup): void {
    $query = nextAlias();
    $values = [];
    $build = static function () use ($query, $matches, $invert, $lookup, &$values): Container {
        return container([
            ConfigKey::PARAMETER_RESOLVERS => [
                1000 => new LabelResolver($query, $lookup, invert: $invert),
                900 => static function (Container $di) use ($query, $matches, &$values): ParameterResolverInterface {
                    $values[] = fixtureInstance($di->get(Product::class), Product::class)->label;
                    class_alias($matches ? Label::class : OtherLabel::class, $query);
                    return new OptionalTypeResolver($query . 'NeverDeclared');
                },
            ],
        ]);
    };

    if ($matches) {
        expect($build)->toThrow(InvalidConfigurationException::class, LabelResolver::class);
    } else {
        expect(fixtureInstance($build()->get(Product::class), Product::class)->label)->toBe($invert ? 'attribute' : 'default');
    }
    expect($values)->toBe([$invert ? 'attribute' : 'default']);
})->with(['matching alias' => true, 'unrelated alias' => false])
    ->with(['new earlier match' => false, 'former winner loses support' => true])
    ->with(['presence lookup' => 'presence', 'native reflection' => 'native']);

test('bootstrap rechecks an alias revealed while proving a later parameter', function (bool $registered): void {
    $query = nextAlias();
    $type = $query . 'Trigger';
    $label = new LabelResolver($query);
    $observe = static function (Container $di) use ($registered, $label, $type): ParameterResolverInterface {
        $di->get(Product::class);
        $di->get(Trigger::class);
        return $registered ? new OptionalTypeResolver($type, 'probe') : $label;
    };
    $loader = static function (string $class) use ($query, $type): void {
        if ($class === $type && !class_exists($query, false)) {
            class_alias(Label::class, $query);
        }
    };
    spl_autoload_register($loader);
    try {
        expect(fn() => container([
            ConfigKey::PARAMETER_RESOLVERS => $registered
                ? [1000 => $label, 900 => $observe]
                : [1000 => $observe, 900 => new OptionalTypeResolver($type, 'probe')],
        ]))->toThrow(InvalidConfigurationException::class, LabelResolver::class);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['original classifier' => true, 'new classifier' => false]);

test('a bootstrap Fiber verifies aliases revealed during its post-publication classification check', function (bool $matches): void {
    $query = nextAlias();
    $type = $query . 'Trigger';
    $state = new class () {
        /** @var Fiber<mixed,mixed,mixed,mixed>|null */
        public ?Fiber $fiber = null;
    };
    $register = static function (Container $di) use ($state, $query): ParameterResolverInterface {
        $state->fiber = new Fiber(static fn(): mixed => $di->get(Product::class));
        expect($state->fiber->start())->toBe('value paused');
        return new LabelResolver($query);
    };
    $loader = static function (string $class) use ($query, $type, $matches): void {
        if ($class === $type && !class_exists($query, false)) {
            class_alias($matches ? Label::class : OtherLabel::class, $query);
        }
    };
    spl_autoload_register($loader);
    try {
        $di = container([
            ConfigKey::PARAMETER_RESOLVERS => [
                500 => new SuspendingLabelResolver(),
                1000 => $register,
                900 => new OptionalTypeResolver($type),
            ],
        ]);
        $fiber = $state->fiber ?? throw new LogicException('Expected the suspended resolution.');
        if ($matches) {
            expect(fn() => $fiber->resume())->toThrow(InvalidConfigurationException::class, LabelResolver::class);
        } else {
            $fiber->resume();
            expect($fiber->getReturn())->toBe($di->get(Product::class));
        }
        expect(class_exists($query, false))->toBeTrue()
            ->and(fixtureInstance($di->get(Product::class), Product::class)->label)->toBe($matches ? 'attribute' : 'original');
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['matching alias' => true, 'unrelated alias' => false]);

test('nested classification in one execution keeps the original attribute lookup', function (string $lookup): void {
    $query = nextAlias();
    $type = $query . 'Trigger';
    $outer = \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::PARAMETER_RESOLVERS => [1000 => new LabelResolver($query, $lookup), 900 => new OptionalTypeResolver($type)],
    ])[1]->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $outer);
    $inner = \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::PARAMETER_RESOLVERS => [1000 => new LabelResolver(nextAlias(), $lookup)],
    ])[1]->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $inner);
    $target = $outer->target(new ReflectionParameter(__NAMESPACE__ . '\\render', 0));
    $loader = static function (string $class) use ($query, $type, $inner, $target): void {
        if ($class === $type && !class_exists($query, false)) {
            $inner->resolveTargets([$target]);
            class_alias(Label::class, $query);
        }
    };
    spl_autoload_register($loader);
    try {
        expect($outer->resolveTargets([$target]))->toBe(['attribute']);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['presence lookup' => 'presence', 'native reflection' => 'native']);

test('an explicit availability observation records only unavailable names in the current annotated target scope', function (): void {
    $targets = new ParameterTargets();
    $target = $targets->create(new ReflectionParameter(__NAMESPACE__ . '\\render', 0));
    $plain = $targets->create(new ReflectionParameter(static fn(string $value): string => $value, 0));
    $missing = nextAlias();
    $target->watchAttributeAvailability(nextAlias());
    $observe = static function (ParameterTarget $current) use ($missing): string {
        $current->watchAttributeAvailability('');
        $current->watchAttributeAvailability(Label::class);
        $current->watchAttributeAvailability($missing);
        $current->watchAttributeAvailability($missing);
        return 'ready';
    };

    expect($targets->observeAttributeQueries($target, $observe))->toBe(['ready', [$missing]])
        ->and($targets->observeAttributeQueries($target, static fn(): string => 'finished'))->toBe(['finished', []])
        ->and($targets->observeAttributeQueries($plain, $observe))->toBe(['ready', []]);
});

test('attribute observation closes its own scope when a nested classifier throws', function (): void {
    $targets = new ParameterTargets();
    $target = $targets->create(new ReflectionParameter(__NAMESPACE__ . '\\render', 0));
    $methods = get_class_methods($target);
    expect($methods)->not->toContain('beginAttributeQueries')
        ->and($methods)->not->toContain('endAttributeQueries');

    $outer = nextAlias();
    $inner = nextAlias();
    $failure = new DomainException('The nested classifier failed.');
    [$supported, $queries] = $targets->observeAttributeQueries(
        $target,
        static function (ParameterTarget $observed) use ($outer, $inner, $failure, $targets): bool {
            $observed->hasAttribute($outer);
            try {
                $targets->observeAttributeQueries($observed, static function (ParameterTarget $nested) use ($inner, $failure): never {
                    $nested->hasAttribute($inner);
                    throw $failure;
                });
            } catch (DomainException $error) {
                expect($error)->toBe($failure);
                return $observed->hasAttribute(Label::class);
            }
            throw new LogicException('Expected the nested classifier to throw.');
        },
    );

    expect($supported)->toBeTrue()
        ->and($queries)->toBe([$outer])
        ->and($targets->observeAttributeQueries($target, static fn(): string => 'ready'))->toBe(['ready', []]);
});

test('a second target factory observes its own view of an existing target', function (): void {
    $owner = new ParameterTargets();
    $other = new ParameterTargets();
    $target = $owner->create(new ReflectionParameter(__NAMESPACE__ . '\\render', 0));
    $outer = nextAlias();
    $inner = nextAlias();

    [[$view, $otherQueries], $ownerQueries] = $owner->observeAttributeQueries(
        $target,
        static function (ParameterTarget $current) use ($other, $outer, $inner): array {
            return $other->observeAttributeQueries($current, static function (ParameterTarget $copy) use ($current, $outer, $inner): ParameterTarget {
                $current->watchAttributeAvailability($outer);
                $copy->watchAttributeAvailability($inner);
                return $copy;
            });
        },
    );

    expect($view)->not->toBe($target)
        ->and($otherQueries)->toBe([$inner])
        ->and($ownerQueries)->toBe([$outer]);
});

test('observations of the same parameter remain independent across suspended Fibers', function (string $lookup): void {
    $query = nextAlias();
    $build = static fn(LabelResolver $resolver): ParametersResolver => \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::PARAMETER_RESOLVERS => [1000 => $resolver],
    ])[1]->parameters;
    $first = $build(new LabelResolver($query, $lookup, pauseOnMiss: true));
    $second = $build(new LabelResolver(nextAlias(), $lookup, pauseOnMiss: true));
    $target = $first->target(new ReflectionParameter(__NAMESPACE__ . '\\render', 0));
    $chosen = static fn(ParametersResolver $parameters): bool => $parameters->resolveTargets([$target]) === ['attribute'];
    $one = new Fiber(static fn(): bool => $chosen($first));
    $two = new Fiber(static fn(): bool => $chosen($second));
    expect($one->start())->toBe('classification paused')
        ->and($two->start())->toBe('classification paused');

    class_alias(Label::class, $query);
    $one->resume();
    expect($one->getReturn())->toBeTrue();

    $observation = WeakReference::create($two);
    unset($two);
    expect($observation->get())->toBeNull()
        ->and($chosen($second))->toBeFalse();
})->with(['presence lookup' => 'presence', 'native reflection' => 'native']);
