<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\BootstrapFinalizationContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use ReflectionAttribute;
use stdClass;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_CLASS)]
final class BootstrapFinalizationExtension {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class BootstrapFinalizationUnrelatedMetadata {}

final class BootstrapFinalizationCapture
{
    public ?Container $container = null;
    public bool $ready = false;
}

abstract class BootstrapFinalizationState
{
    public static int $constructions = 0;

    public ?stdClass $dependency = null;

    public function __construct(public string $value = 'fallback')
    {
        ++self::$constructions;
    }
}

final readonly class BootstrapFinalizationClassifier implements ParameterResolverInterface
{
    public function __construct(private string $marker, private ?string $declaringClass = null) {}

    public function supports(ParameterTarget $target): bool
    {
        return ($this->declaringClass === null || $target->reflection->getDeclaringClass()?->getName() === $this->declaringClass)
            && $target->reflection->getAttributes($this->marker, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

test('bootstrap finalization rejects semantics loaded while checking a later parameter resolver', function (string $kind): void {
    BootstrapFinalizationState::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'FinalizationAttribute_' . $suffix;
    $target = 'FinalizationTarget_' . $suffix;
    $marker = __NAMESPACE__ . '\\FinalizationMarker_' . $suffix;
    $body = $kind === 'property'
        ? sprintf('#[%s] public ?\\stdClass $dependency = null;', $attribute)
        : sprintf('public function __construct(#[%s("selected")] string $value = "fallback") { parent::__construct($value); }', $attribute);
    eval(sprintf('namespace %s; final class %s extends BootstrapFinalizationState { %s }', __NAMESPACE__, $target, $body));
    $class = __NAMESPACE__ . '\\' . $target;
    $loads = 0;
    $autoload = static function (string $requested) use ($marker, $attribute, $kind, &$loads): void {
        if ($requested !== $marker) {
            return;
        }
        ++$loads;
        eval(sprintf('namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s {}', __NAMESPACE__, substr($marker, strrpos($marker, '\\') + 1)));
        class_alias($kind === 'property' ? Inject::class : EntryId::class, __NAMESPACE__ . '\\' . $attribute);
    };
    spl_autoload_register($autoload);

    try {
        expect(fn() => container([
            ConfigKey::SERVICES => ['selected' => 'injected'],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function (Container $di) use ($class): AttributeDefinition {
                    $di->get($class);
                    return new AttributeDefinition(BootstrapFinalizationExtension::class);
                },
            ],
            ConfigKey::PARAMETER_RESOLVERS => [500 => new BootstrapFinalizationClassifier($marker)],
        ]))->toThrow(InvalidConfigurationException::class, $class);

        expect(BootstrapFinalizationState::$constructions)->toBe(1)
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['property', 'parameter']);

test('bootstrap finalization permits unrelated metadata loaded by later resolver classification', function (): void {
    BootstrapFinalizationState::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'FinalizationMetadata_' . $suffix;
    $target = 'FinalizationUnchanged_' . $suffix;
    $marker = __NAMESPACE__ . '\\FinalizationUnrelatedMarker_' . $suffix;
    eval(sprintf('namespace %s; final class %s extends BootstrapFinalizationState { #[%s] public ?\\stdClass $dependency = null; }', __NAMESPACE__, $target, $attribute));
    $class = __NAMESPACE__ . '\\' . $target;
    $autoload = static function (string $requested) use ($marker, $attribute): void {
        if ($requested === $marker) {
            eval(sprintf('namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s {}', __NAMESPACE__, substr($marker, strrpos($marker, '\\') + 1)));
            class_alias(BootstrapFinalizationUnrelatedMetadata::class, __NAMESPACE__ . '\\' . $attribute);
        }
    };
    spl_autoload_register($autoload);

    try {
        $di = container([
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function (Container $di) use ($class): AttributeDefinition {
                    $di->get($class);
                    return new AttributeDefinition(BootstrapFinalizationExtension::class);
                },
            ],
            ConfigKey::PARAMETER_RESOLVERS => [500 => new BootstrapFinalizationClassifier($marker)],
        ]);
        $cached = $di->get($class);
        if (!$cached instanceof BootstrapFinalizationState) {
            throw new LogicException('Expected the bootstrap dependency.');
        }

        expect(BootstrapFinalizationState::$constructions)->toBe(1)
            ->and($di->get($class))->toBe($cached)
            ->and($cached->dependency)->toBeNull()
            ->and($cached->value)->toBe('fallback');

        $fresh = $di->make($class);
        if (!$fresh instanceof BootstrapFinalizationState) {
            throw new LogicException('Expected the fresh dependency.');
        }
        expect($fresh)->not->toBe($cached)
            ->and($fresh->dependency)->toBeNull()
            ->and($fresh->value)->toBe('fallback')
            ->and(BootstrapFinalizationState::$constructions)->toBe(2);
    } finally {
        spl_autoload_unregister($autoload);
    }
});

test('bootstrap finalization records dependencies first created during the final proof', function (string $kind, bool $changesSemantics): void {
    BootstrapFinalizationState::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'ReentrantFinalizationAttribute_' . $suffix;
    $target = 'ReentrantFinalizationTarget_' . $suffix;
    $marker = __NAMESPACE__ . '\\ReentrantFinalizationMarker_' . $suffix;
    $body = $kind === 'property'
        ? sprintf('#[%s] public ?\\stdClass $dependency = null;', $attribute)
        : sprintf('public function __construct(#[%s("selected")] string $value = "fallback") { parent::__construct($value); }', $attribute);
    eval(sprintf('namespace %s; final class %s extends BootstrapFinalizationState { %s }', __NAMESPACE__, $target, $body));
    $class = __NAMESPACE__ . '\\' . $target;
    $captured = new BootstrapFinalizationCapture();
    $loads = 0;
    $autoload = static function (string $requested) use ($marker, $attribute, $class, $kind, $changesSemantics, $captured, &$loads): void {
        if ($requested !== $marker) {
            return;
        }
        ++$loads;
        eval(sprintf('namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s {}', __NAMESPACE__, substr($marker, strrpos($marker, '\\') + 1)));
        $di = $captured->container;
        if (!$di instanceof Container) {
            throw new LogicException('Expected the bootstrapping container.');
        }
        $di->get($class);
        $alias = $changesSemantics
            ? ($kind === 'property' ? Inject::class : EntryId::class)
            : BootstrapFinalizationUnrelatedMetadata::class;
        class_alias($alias, __NAMESPACE__ . '\\' . $attribute);
    };
    spl_autoload_register($autoload);

    try {
        $config = [
            ConfigKey::SERVICES => ['selected' => 'injected'],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function (Container $di) use ($captured): AttributeDefinition {
                    $captured->container = $di;
                    $di->call(static fn(string $seed = 'seed'): string => $seed);
                    return new AttributeDefinition(BootstrapFinalizationExtension::class);
                },
            ],
            ConfigKey::PARAMETER_RESOLVERS => [500 => new BootstrapFinalizationClassifier($marker)],
        ];
        $build = static fn() => container($config);

        if ($changesSemantics) {
            expect($build)->toThrow(InvalidConfigurationException::class, $class);
        } else {
            $di = $build();
            $cached = $di->get($class);
            if (!$cached instanceof BootstrapFinalizationState) {
                throw new LogicException('Expected the bootstrap dependency.');
            }
            expect($di->get($class))->toBe($cached)
                ->and($cached->dependency)->toBeNull()
                ->and($cached->value)->toBe('fallback');
            $fresh = $di->make($class);
            if (!$fresh instanceof BootstrapFinalizationState) {
                throw new LogicException('Expected the fresh dependency.');
            }
            expect($fresh)->not->toBe($cached)
                ->and($fresh->dependency)->toBeNull()
                ->and($fresh->value)->toBe('fallback');
        }

        expect(BootstrapFinalizationState::$constructions)->toBe($changesSemantics ? 1 : 2)
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['property', 'parameter'])->with([true, false]);

test('bootstrap finalization rechecks dependencies discovered by the final availability poll', function (string $kind, bool $changesSemantics, bool $deferred): void {
    BootstrapFinalizationState::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'PollFinalizationAttribute_' . $suffix;
    $target = 'PollFinalizationTarget_' . $suffix;
    $seed = __NAMESPACE__ . '\\PollFinalizationSeed_' . $suffix;
    $pending = __NAMESPACE__ . '\\PollFinalizationPending_' . $suffix;
    $trigger = __NAMESPACE__ . '\\PollFinalizationTrigger_' . $suffix;
    $body = $kind === 'property'
        ? sprintf('#[%s] public ?\\stdClass $dependency = null;', $attribute)
        : sprintf('public function __construct(#[%s("selected")] string $value = "fallback") { parent::__construct($value); }', $attribute);
    eval(sprintf('namespace %s; final class %s extends BootstrapFinalizationState { %s }', __NAMESPACE__, $target, $body));
    eval(sprintf('namespace %s; #[PollFinalizationPending_%s] final class PollFinalizationSeed_%s { public function __construct(public string $value = "seed") {} }', __NAMESPACE__, $suffix, $suffix));
    $class = __NAMESPACE__ . '\\' . $target;
    $captured = new BootstrapFinalizationCapture();
    $qualifiedAttribute = __NAMESPACE__ . '\\' . $attribute;
    $alias = $changesSemantics
        ? ($kind === 'property' ? Inject::class : EntryId::class)
        : BootstrapFinalizationUnrelatedMetadata::class;
    $armed = false;
    $loads = 0;
    $autoload = static function (string $requested) use ($trigger, $pending, $qualifiedAttribute, $class, $alias, $deferred, $captured, &$armed, &$loads): void {
        if ($requested === $qualifiedAttribute && $captured->ready) {
            class_alias($alias, $requested);
            return;
        }
        if ($requested === $trigger) {
            eval(sprintf('namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s {}', __NAMESPACE__, substr($trigger, strrpos($trigger, '\\') + 1)));
            $armed = true;
        }
        if ($requested !== $pending || !$armed || $loads !== 0) {
            return;
        }
        ++$loads;
        $di = $captured->container;
        if (!$di instanceof Container) {
            throw new LogicException('Expected the bootstrapping container.');
        }
        $di->get($class);
        if ($deferred) {
            $captured->ready = true;
        } else {
            class_alias($alias, $qualifiedAttribute);
        }
        // The original marker deliberately remains unavailable. Only the new
        // dependency reveals metadata after this poll's pending-class snapshot.
    };
    spl_autoload_register($autoload);

    try {
        $config = [
            ConfigKey::SERVICES => ['selected' => 'injected'],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function (Container $di) use ($seed, $captured): AttributeDefinition {
                    $captured->container = $di;
                    $di->get($seed);
                    return new AttributeDefinition(BootstrapFinalizationExtension::class);
                },
            ],
            ConfigKey::PARAMETER_RESOLVERS => [500 => new BootstrapFinalizationClassifier($trigger, $seed)],
        ];
        $build = static fn() => container($config);

        if ($changesSemantics) {
            expect($build)->toThrow(InvalidConfigurationException::class, $class);
        } else {
            $di = $build();
            $cached = $di->get($class);
            $fresh = $di->make($class);
            if (!$cached instanceof BootstrapFinalizationState || !$fresh instanceof BootstrapFinalizationState) {
                throw new LogicException('Expected bootstrap and fresh dependencies.');
            }
            expect($di->get($class))->toBe($cached)
                ->and($fresh)->not->toBe($cached)
                ->and($cached->dependency)->toBeNull()
                ->and($fresh->dependency)->toBeNull()
                ->and($cached->value)->toBe('fallback')
                ->and($fresh->value)->toBe('fallback');
        }

        expect(BootstrapFinalizationState::$constructions)->toBe($changesSemantics ? 1 : 2)
            ->and($loads)->toBe(1)
            ->and(class_exists($pending, false))->toBeFalse();
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['property', 'parameter'])->with([true, false])
    ->with(['declared during poll' => false, 'loadable after reentrant discovery' => true]);
