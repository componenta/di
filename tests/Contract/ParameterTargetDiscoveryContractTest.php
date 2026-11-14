<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ParameterTargetDiscovery;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Label
{
    /** @var list<string> */
    public static array $constructed = [];

    public function __construct(public readonly string $value = 'first')
    {
        self::$constructed[] = $value;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class KnownLabel extends Label {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Metadata {}

trait InvalidMetadata {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class MetadataResolver implements ParameterResolverInterface
{
    public bool $armed = false;
    public int $executions = 0;

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        ++$this->executions;
        $this->armed = true;
        $attribute = $target->firstAttribute(Label::class);
        return $attribute === null ? null : [$target->position, $attribute->value];
    }
}

function declareTarget(string $attributes): string
{
    $name = 'consume_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; function %s(#[%s] string $value): string { return $value; }',
        __NAMESPACE__,
        $name,
        $attributes,
    ));
    return __NAMESPACE__ . '\\' . $name;
}

beforeEach(function (): void {
    Label::$constructed = [];
});

afterEach(function (): void {
    Label::$constructed = [];
});

test('parameter attribute discovery includes earlier declarations revealed by later autoload cascades', function (bool $presence): void {
    $suffix = bin2hex(random_bytes(5));
    $early = 'Early_' . $suffix;
    $middle = 'Middle_' . $suffix;
    $late = 'Late_' . $suffix;
    $bridge = 'Bridge_' . $suffix;
    $function = declareTarget($early . '("first"), ' . $middle . '("middle"), ' . $late . ($presence ? '' : '("last")'));
    $target = new ParameterTarget(new ReflectionParameter($function, 0));
    $loads = [];
    $loader = static function (string $class) use ($early, $middle, $late, $bridge, $presence, &$loads): void {
        $loads[] = $class;
        if ($class === __NAMESPACE__ . '\\' . $middle) {
            class_alias(KnownLabel::class, __NAMESPACE__ . '\\' . $early);
        } elseif ($class === __NAMESPACE__ . '\\' . $late) {
            class_exists(__NAMESPACE__ . '\\' . $bridge);
        } elseif ($class === __NAMESPACE__ . '\\' . $bridge) {
            class_alias(KnownLabel::class, __NAMESPACE__ . '\\' . $middle);
            class_alias($presence ? Metadata::class : KnownLabel::class, __NAMESPACE__ . '\\' . $late);
            class_alias(Metadata::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        if ($presence) {
            expect($target->hasAttribute(Label::class))->toBeTrue()
                ->and($target->hasAttribute(Label::class))->toBeTrue()
                ->and(Label::$constructed)->toBe([]);
        } else {
            $first = $target->firstAttribute(Label::class);
            $second = $target->firstAttribute(Label::class);
            expect($first?->value)->toBe('first')
                ->and($second?->value)->toBe('first')
                ->and($second)->not->toBe($first)
                ->and(Label::$constructed)->toBe(['first', 'first']);
        }
        expect($loads)->toBe(array_map(
            static fn(string $name): string => __NAMESPACE__ . '\\' . $name,
            [$early, $middle, $late, $bridge],
        ));
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['presence' => true, 'first instance' => false]);

test('parameter attribute discovery observes a query alias revealed after an already loaded declaration', function (bool $presence): void {
    $suffix = bin2hex(random_bytes(5));
    $late = 'AliasLoader_' . $suffix;
    /** @var class-string<Label> $query Alias declared by the autoloader below. */
    $query = __NAMESPACE__ . '\\Query_' . $suffix;
    $target = new ParameterTarget(new ReflectionParameter(declareTarget('KnownLabel("first"), ' . $late), 0));
    $loader = static function (string $class) use ($late, $query): void {
        if ($class === __NAMESPACE__ . '\\' . $late) {
            class_alias(Label::class, $query);
            class_alias(Metadata::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        if ($presence) {
            expect($target->hasAttribute($query))->toBeTrue()
                ->and($target->hasAttribute($query))->toBeTrue()
                ->and(Label::$constructed)->toBe([]);
        } else {
            $first = $target->firstAttribute($query);
            $second = $target->firstAttribute($query);
            expect($first)->toBeInstanceOf(Label::class)
                ->and($second)->toBeInstanceOf(Label::class)
                ->and($second)->not->toBe($first)
                ->and(Label::$constructed)->toBe(['first', 'first']);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['presence' => true, 'first instance' => false]);

test('unavailable parameter metadata is not retried by the same attribute lookup', function (bool $presence): void {
    $suffix = bin2hex(random_bytes(5));
    $first = 'MissingFirst_' . $suffix;
    $second = 'MissingSecond_' . $suffix;
    $target = new ParameterTarget(new ReflectionParameter(declareTarget($first . ', ' . $second), 0));
    $loads = [];
    $loader = static function (string $class) use (&$loads): void {
        $loads[] = $class;
    };
    spl_autoload_register($loader);

    try {
        for ($call = 0; $call < 2; ++$call) {
            expect($presence ? $target->hasAttribute(Label::class) : $target->firstAttribute(Label::class))
                ->toBe($presence ? false : null);
        }
        expect($loads)->toBe(array_map(
            static fn(string $name): string => __NAMESPACE__ . '\\' . $name,
            [$first, $second, $first, $second],
        ))->and(Label::$constructed)->toBe([]);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['presence' => true, 'first instance' => false]);

test('successful parameter attribute presence checks do not load unrelated declarations or construct attributes', function (bool $exact): void {
    $missing = 'Untouched_' . bin2hex(random_bytes(5));
    $attributes = $exact ? $missing . ', KnownLabel' : 'KnownLabel, ' . $missing;
    $target = new ParameterTarget(new ReflectionParameter(declareTarget($attributes), 0));
    $loader = static function (string $class): never {
        throw new \LogicException('Successful presence inspection must not autoload ' . $class);
    };
    spl_autoload_register($loader);

    try {
        expect($target->hasAttribute($exact ? KnownLabel::class : Label::class))->toBeTrue()
            ->and(Label::$constructed)->toBe([]);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['exact declaration' => true, 'inherited first declaration' => false]);

test('loaded trait metadata retains its native instantiation failure', function (): void {
    $target = new ParameterTarget(new ReflectionParameter(declareTarget('InvalidMetadata'), 0));

    expect($target->hasAttribute(InvalidMetadata::class))->toBeTrue()
        ->and(fn() => $target->firstAttribute(InvalidMetadata::class))->toThrow(\Error::class)
        ->and(Label::$constructed)->toBe([]);
});

test('custom resolvers observe the first declared metadata on both cold and repeated callable invocations', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(5));
    $early = 'ResolverEarly_' . $suffix;
    $late = 'ResolverLate_' . $suffix;
    $function = declareTarget($early . '("first"), ' . $late . '("last")');
    $resolver = new MetadataResolver();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(Metadata::class, rules: $uncached ? [new NoOpRule()] : []))
        ->addParameterResolver($resolver, 2000)
        ->build();
    $loader = static function (string $class) use ($early, $late, $resolver): void {
        if ($resolver->armed && $class === __NAMESPACE__ . '\\' . $late) {
            class_alias(KnownLabel::class, __NAMESPACE__ . '\\' . $early);
            class_alias(KnownLabel::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        expect($container->call($function))->toBe('first')
            ->and($container->call($function))->toBe('first')
            ->and($resolver->executions)->toBe(2)
            ->and(Label::$constructed)->toBe(['first', 'first']);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached plan' => false, 'uncached plan' => true]);
