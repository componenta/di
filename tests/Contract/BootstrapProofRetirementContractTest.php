<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BootstrapProofRetirement;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use stdClass;
use WeakReference;

use function Componenta\DI\Tests\Support\container;

#[Attribute]
final class Metadata {}

final readonly class ProofCapture
{
    public function __construct(private Closure $onRelease) {}

    public function __destruct()
    {
        ($this->onRelease)();
    }
}

test('bootstrap verifies declarations made visible while releasing its proof', function (bool $related): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Product_' . $suffix;
    $attribute = __NAMESPACE__ . '\\PendingInject_' . $suffix;
    eval(sprintf(
        'namespace %s; final class Product_%s { #[PendingInject_%s] public ?\\stdClass $value = null; }',
        __NAMESPACE__,
        $suffix,
        $suffix,
    ));
    $events = [];
    $build = static function () use ($class, $attribute, $related, &$events): Container {
        return container([
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function (Container $di) use ($class, $attribute, $related, &$events): AttributeDefinition {
                    $di->get($class);
                    $token = new ProofCapture(static function () use ($attribute, $related, &$events): void {
                        $events[] = 'released';
                        class_alias($related ? Inject::class : Metadata::class, $attribute);
                    });
                    $weak = WeakReference::create($token);
                    $call = static fn(string $value = 'ready'): string => $value . $token::class;
                    $di->call($call);
                    unset($token, $call);
                    $events[] = $weak->get() !== null ? 'retained for proof' : 'released before proof';
                    return new AttributeDefinition(ExtraMetadata::class);
                },
            ],
        ]);
    };

    if ($related) {
        expect($build)->toThrow(InvalidConfigurationException::class, $attribute);
    } else {
        $di = $build();
        $target = $di->get($class);
        expect($target)->not->toBeInstanceOf(stdClass::class)
            ->and($di->get($class))->toBe($target);
    }
    expect($events)->toBe(['retained for proof', 'released']);
})->with(['related declaration' => true, 'ordinary metadata' => false]);

#[Attribute]
final class ExtraMetadata {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelPolicy {}

class Label
{
    public static int $constructions = 0;
    public string $label;
}

final class LabelResolver implements ParameterResolverInterface
{
    public function __construct(private readonly string $attribute) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute($this->attribute);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, 'classified'];
    }
}

final class PassiveResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

test('bootstrap verifies a parameter classification changed by an unavailable attribute name', function (string $stage, bool $matches, bool $queryAlias): void {
    Label::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Label_' . $suffix;
    $pending = __NAMESPACE__ . '\\PendingLabel_' . $suffix;
    $attribute = $queryAlias ? LabelPolicy::class : $pending;
    $query = $queryAlias ? $pending : LabelPolicy::class;
    eval(sprintf(
        'namespace %s; final class Label_%s extends Label { public function __construct(#[\\%s] string $label = "fallback") { ++Label::$constructions; $this->label = $label; } }',
        __NAMESPACE__,
        $suffix,
        $attribute,
    ));
    $events = [];
    $load = static function () use ($pending, $matches, &$events): void {
        $events[] = 'loaded';
        class_alias($matches ? LabelPolicy::class : Metadata::class, $pending);
    };
    $own = null;
    $newResolver = static function (Container $di) use ($stage, $class, $attribute, $load, &$events, &$own): ParameterResolverInterface {
        if ($stage === 'live closure' || $stage === 'semantic callback') {
            $own = eval(sprintf(
                'namespace %s; return static fn(%s#[\\%s] string $label = "fallback"): string => $label;',
                __NAMESPACE__,
                $stage === 'semantic callback' ? 'mixed $first, ' : '',
                $attribute,
            ));
            if (!$own instanceof Closure) {
                throw new LogicException('Expected a live callable.');
            }
            $events[] = callLabel($di, $own, $stage);
        } else {
            $events[] = $di->getAs($class, Label::class)->label;
        }

        if ($stage === 'extension') {
            $load();
        } else {
            $token = new ProofCapture($load);
            $di->call(static fn(string $ignored = ''): string => $ignored . $token::class);
            $weak = WeakReference::create($token);
            unset($token);
            $events[] = $weak->get() === null ? 'released early' : 'retained for proof';
        }

        return new PassiveResolver();
    };
    $build = static fn(): Container => container([
        ConfigKey::PARAMETER_RESOLVERS => [500 => new LabelResolver($query), 499 => $newResolver],
    ]);

    if ($matches) {
        expect($build)->toThrow(InvalidConfigurationException::class, LabelResolver::class);
    } else {
        $di = $build();
        if ($own instanceof Closure) {
            expect(callLabel($di, $own, $stage))->toBe('fallback');
        } else {
            expect($di->getAs($class, Label::class)->label)->toBe('fallback');
        }
    }
    expect($events)->toBe($stage === 'extension'
        ? ['fallback', 'loaded']
        : ['fallback', 'retained for proof', 'loaded'])
        ->and(Label::$constructions)->toBe($own instanceof Closure ? 0 : 1);
})->with(['later extension' => 'extension', 'cleanup of a named service' => 'named', 'cleanup of a live closure' => 'live closure', 'cleanup of a semantic callback' => 'semantic callback'])
    ->with(['related source' => true, 'ordinary metadata' => false])
    ->with(['late declaration' => false, 'late alias query' => true]);

function callLabel(Container $di, Closure $call, string $stage): mixed
{
    if ($stage !== 'semantic callback') {
        return $di->call($call);
    }
    $binder = new AttributeCallbackInvoker($di->getAs(ParametersResolver::class, ParametersResolver::class), $di);
    return $binder->invoke($call, null);
}

final readonly class ReleasedLabel
{
    public function __construct(public string $value) {}
}

final readonly class IgnoredLookup implements ParameterResolverInterface
{
    public function __construct(private string $attribute) {}

    public function supports(ParameterTarget $target): bool
    {
        $target->hasAttribute($this->attribute);
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        throw new LogicException('A declined resolver must not produce a value.');
    }
}

final readonly class LoadAliasOnAnchor implements ParameterResolverInterface
{
    public function __construct(private string $trigger, private string $alias) {}

    public function supports(ParameterTarget $target): bool
    {
        $target->hasAttribute($this->trigger);
        if ($target->name === 'anchor' && class_exists($this->trigger, false) && !class_exists($this->alias, false)) {
            class_alias(LabelPolicy::class, $this->alias);
        }
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

function liveAnchor(#[Metadata] string $anchor = 'unchanged'): string
{
    return $anchor;
}

test('bootstrap checks attribute matching after a temporary factory closure is released', function (
    string $scenario,
    bool $targeted,
): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = __NAMESPACE__ . '\\ReleasedLabel_' . $suffix;
    $trigger = __NAMESPACE__ . '\\UnrelatedLabel_' . $suffix;
    $missing = 'MissingRetirementMarker_' . $suffix;
    $declared = $scenario === 'late declaration' ? $alias : LabelPolicy::class;
    $required = $scenario === 'late declaration' ? LabelPolicy::class : $alias;
    $state = new class () {
        public int $factories = 0;
        public int $releases = 0;
        public int $values = 0;
        public int $autoloadsAfterRelease = 0;
        /** @var WeakReference<ProofCapture>|null */
        public ?WeakReference $capture = null;
    };
    $resolve = static function (ParameterTarget $target, ParameterResolutionContext $context) use ($state): array {
        ++$state->values;
        return [$target->position, 'classified'];
    };
    $classifier = $scenario === 'ignored query' ? new IgnoredLookup($alias) : new AttributeMatchingParameterResolver($required, $resolve);
    $later = $scenario === 'later live check' ? new LoadAliasOnAnchor($trigger, $alias) : new PassiveResolver();
    if ($scenario === 'early alias') {
        class_alias(LabelPolicy::class, $alias);
    }
    $serviceFactory = static function (ContainerValue $services) use ($scenario, $alias, $trigger, $declared, $missing, $state): ReleasedLabel {
        ++$state->factories;
        $di = $services->container;
        if (!$di instanceof Container) {
            throw new LogicException('Expected the DI container.');
        }
        $capture = new ProofCapture(static function () use ($scenario, $alias, $trigger, $state): void {
            ++$state->releases;
            $name = $scenario === 'later live check' ? $trigger : $alias;
            if (!class_exists($name, false)) {
                class_alias(
                    in_array($scenario, ['unrelated alias', 'later live check'], true) ? Metadata::class : LabelPolicy::class,
                    $name,
                );
            }
        });
        $closure = eval(sprintf(
            'namespace %s; return static fn(#[\\%s, %s] string $label = "fallback"): array => [$label, $capture];',
            __NAMESPACE__,
            $declared,
            $missing,
        ));
        if (!$closure instanceof Closure) {
            throw new LogicException('Expected a temporary callable.');
        }
        $state->capture ??= WeakReference::create($capture);
        $result = $di->call($closure, $scenario === 'explicit input' ? ['label' => 'provided'] : []);
        if (!is_array($result) || !is_string($result[0] ?? null)) {
            throw new LogicException('Expected the resolved label.');
        }
        return new ReleasedLabel($result[0]);
    };
    $sections = new DependencyDefinitions([
        ConfigKey::FACTORIES => [ReleasedLabel::class => $serviceFactory],
        ConfigKey::PARAMETER_RESOLVERS => [
            1000 => $classifier,
            950 => $later,
            900 => static function (Container $di) use ($scenario): ParameterResolverInterface {
                $di->get(ReleasedLabel::class);
                if ($scenario === 'later live check') {
                    $di->call(__NAMESPACE__ . '\\liveAnchor');
                }
                return new PassiveResolver();
            },
        ],
    ]);
    $build = static function () use ($targeted, $sections): Container {
        $factory = new ContainerFactory();
        $config = new Config([], new Environment([]));
        $services = $targeted
            ? $factory->createWithTargetedAttributeDiscovery($config, $sections)
            : $factory->create($config, $sections);
        if (!$services->container instanceof Container) {
            throw new LogicException('Expected the DI container.');
        }
        return $services->container;
    };
    $loader = static function (string $name) use ($state, $missing): void {
        if ($name === __NAMESPACE__ . '\\' . $missing && $state->releases !== 0) {
            ++$state->autoloadsAfterRelease;
        }
    };
    spl_autoload_register($loader);
    try {
        if (in_array($scenario, ['matching alias', 'late declaration', 'later live check'], true)) {
            expect($build)->toThrow(InvalidConfigurationException::class, $required);
        } else {
            $di = $build();
            $shared = $di->getAs(ReleasedLabel::class, ReleasedLabel::class);
            expect($shared->value)->toBe(match ($scenario) {
                'early alias' => 'classified',
                'explicit input' => 'provided',
                default => 'fallback',
            })->and($di->get(ReleasedLabel::class))->toBe($shared);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
    expect($state->factories)->toBe(1)
        ->and($state->capture?->get())->toBeNull()
        ->and($state->releases)->toBe(1)
        ->and($state->values)->toBe($scenario === 'early alias' ? 1 : 0);
    if ($scenario !== 'later live check') {
        expect($state->autoloadsAfterRelease)->toBe(0);
    }
})->with(['matching alias', 'late declaration', 'unrelated alias', 'ignored query', 'explicit input', 'early alias', 'later live check'])
    ->with(['compatibility discovery' => false, 'targeted discovery' => true]);
