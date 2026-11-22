<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeAvailabilityRevision;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use ReflectionProperty;
use RuntimeException;

#[Attribute(Attribute::TARGET_ALL)]
final class UnusedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class ConstructionCounter
{
    public static int $calls = 0;
}

function declareLateAttribute(string $shortName, string $form): void
{
    if ($form === 'alias') {
        class_alias(NoConstructor::class, __NAMESPACE__ . '\\' . $shortName);
        return;
    }
    if ($form === 'interface') {
        eval(sprintf('namespace %s; interface %s {}', __NAMESPACE__, $shortName));
        return;
    }
    eval(sprintf(
        'namespace %s; #[\\Attribute(\\Attribute::TARGET_CLASS)] final readonly class %s extends \\Componenta\\DI\\Attribute\\NoConstructor {}',
        __NAMESPACE__,
        $shortName,
    ));
}

/** @return array<class-string, true> */
function addUnavailableAttributes(AttributeDefinitionRegistry $registry): array
{
    $attributes = [];
    $prefix = __NAMESPACE__ . '\\Unresolved_' . bin2hex(random_bytes(6)) . '_';
    $symbols = count(get_declared_classes()) + count(get_declared_interfaces());
    $count = max(128, intdiv($symbols, 3));
    for ($index = 0; $index < $count; ++$index) {
        /** @var class-string $attribute */
        $attribute = $prefix . $index;
        $registry->definition($attribute);
        $attributes[$attribute] = true;
    }
    return $attributes;
}

test('cached and uncached availability observes a class declared during the same autoload poll', function (bool $uncached, string $form, int $readyOnAttempt): void {
    $suffix = bin2hex(random_bytes(6));
    $shortName = 'LateNoConstructor_' . $suffix;
    $target = 'Target_' . $suffix;
    $attribute = __NAMESPACE__ . '\\' . $shortName;
    eval(sprintf(
        'namespace %s; #[%s] final class %s { private function __construct() { ++ConstructionCounter::$calls; } }',
        __NAMESPACE__,
        $shortName,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class, false)) {
        throw new LogicException('Expected the availability fixture.');
    }
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UnusedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    ConstructionCounter::$calls = 0;
    expect($container->has($class))->toBeFalse()
        ->and(class_exists($attribute, false))->toBeFalse();

    $loads = 0;
    $loader = static function (string $requested) use ($attribute, $shortName, $form, $readyOnAttempt, &$loads): void {
        if ($requested === $attribute && ++$loads === $readyOnAttempt) {
            declareLateAttribute($shortName, $form);
        }
    };
    spl_autoload_register($loader);
    try {
        expect($container->has($class))->toBeTrue()
            ->and(class_exists($attribute, false))->toBeTrue()
            ->and($loads)->toBe($readyOnAttempt)
            ->and(ConstructionCounter::$calls)->toBe(0);

        expect($container->make($class))->toBeInstanceOf($class)
            ->and($container->has($class))->toBeTrue()
            ->and($loads)->toBe($readyOnAttempt)
            ->and(ConstructionCounter::$calls)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['class declaration' => 'class', 'class alias' => 'alias'])
    ->with(['first attempt' => 1, 'fallback attempt' => 2]);

test('availability revision reflects every declaration discovered by the current poll', function (string $form): void {
    $shortName = 'LateDeclaration_' . bin2hex(random_bytes(6));
    /** @var class-string $attribute */
    $attribute = __NAMESPACE__ . '\\' . $shortName;
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($attribute);
    $before = $registry->revision;
    $loads = 0;
    $loader = static function (string $requested) use ($attribute, $shortName, $form, &$loads): void {
        if ($requested === $attribute && ++$loads === 2) {
            declareLateAttribute($shortName, $form);
        }
    };

    spl_autoload_register($loader);
    try {
        expect($registry->revision)->toBe($before + 1)
            ->and($registry->revision)->toBe($before + 1)
            ->and($loads)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['class declaration' => 'class', 'class alias' => 'alias', 'interface declaration' => 'interface']);

test('an unavailable attribute keeps the existing two autoload attempts per poll', function (): void {
    /** @var class-string $attribute */
    $attribute = __NAMESPACE__ . '\\Missing_' . bin2hex(random_bytes(6));
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($attribute);
    $before = $registry->revision;
    $loads = 0;
    $loader = static function (string $requested) use ($attribute, &$loads): void {
        if ($requested === $attribute) {
            ++$loads;
        }
    };

    spl_autoload_register($loader);
    try {
        expect($registry->revision)->toBe($before)->and($loads)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('availability polling preserves an exception from the fallback autoload attempt', function (): void {
    /** @var class-string $attribute */
    $attribute = __NAMESPACE__ . '\\Throwing_' . bin2hex(random_bytes(6));
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($attribute);
    $loads = 0;
    $failure = new RuntimeException('fallback autoload failure');
    $loader = static function (string $requested) use ($attribute, $failure, &$loads): void {
        if ($requested === $attribute && ++$loads === 2) {
            throw $failure;
        }
    };

    spl_autoload_register($loader);
    try {
        $caught = null;
        try {
            $revision = $registry->revision;
            throw new LogicException('Expected an autoload failure, got revision ' . $revision . '.');
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBeInstanceOf(RuntimeException::class)
            ->and($caught)->toBe($failure)
            ->and($caught->getMessage())->toBe('fallback autoload failure')
            ->and($loads)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('loaded-only availability detects late declarations across Fibers with many unresolved names', function (string $form): void {
    $registry = AttributeDefinitionRegistry::withTargetedDiscovery();
    addUnavailableAttributes($registry);
    $shortName = 'FiberDeclaration_' . bin2hex(random_bytes(6));
    /** @var class-string $attribute */
    $attribute = __NAMESPACE__ . '\\' . $shortName;
    /** @var class-string&lowercase-string $lower */
    $lower = strtolower($attribute);
    $registry->definition($attribute);
    $registry->definition($lower);

    expect($registry->revision)->toBe(0);
    $fiber = new Fiber(static function () use ($registry): int {
        Fiber::suspend($registry->revision);
        return $registry->revision;
    });
    expect($fiber->start())->toBe(0);

    $loads = 0;
    $loader = static function (string $name) use ($attribute, $lower, &$loads): void {
        if ($name === $attribute || $name === $lower) {
            ++$loads;
        }
    };
    spl_autoload_register($loader, prepend: true);
    try {
        declareLateAttribute($shortName, $form);
        $fiber->resume();
        expect($fiber->getReturn())->toBe(2)
            ->and($registry->revision)->toBe(2)
            ->and($loads)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['class', 'interface', 'alias']);

test('loaded-only availability tracks aliases that PHP omits from its declaration lists', function (): void {
    $registry = AttributeDefinitionRegistry::withTargetedDiscovery();
    addUnavailableAttributes($registry);
    expect($registry->revision)->toBe(0);

    /** @var class-string $attribute */
    $attribute = "\0Optional_" . bin2hex(random_bytes(6));
    $registry->definition($attribute);
    expect($registry->revision)->toBe(0);

    $aliased = class_alias(UnusedMarker::class, $attribute, autoload: false);
    $revisions = [$registry->revision, $registry->revision];

    expect($aliased)->toBeTrue()->and($revisions)->toBe([1, 1]);
});

test('targeted definition lookup releases the declaration snapshot with the last pending name', function (): void {
    $registry = AttributeDefinitionRegistry::withTargetedDiscovery();
    $attributes = addUnavailableAttributes($registry);
    $snapshot = new ReflectionProperty(AttributeDefinitionRegistry::class, 'checkedDeclarations');
    expect($registry->revision)->toBe(0)->and($snapshot->getValue($registry))->toBeArray();

    $loader = static function (string $name) use ($attributes): void {
        if (isset($attributes[$name])) {
            class_alias(UnusedMarker::class, $name, autoload: false);
        }
    };
    spl_autoload_register($loader, prepend: true);
    try {
        foreach ($attributes as $attribute => $_) {
            $registry->definition($attribute);
        }
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($snapshot->getValue($registry))->toBeNull()->and($registry->revision)->toBe(count($attributes));
});

test('loaded-only availability preserves legacy fallback probes and same-poll reentrant discovery', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $prefix = __NAMESPACE__ . '\\Reentrant_' . bin2hex(random_bytes(6));
    /** @var class-string $first */
    $first = $prefix . '_First';
    /** @var class-string $last */
    $last = $prefix . '_Last';
    /** @var class-string $nested */
    $nested = $prefix . '_Nested';
    $registry->definition($first);
    addUnavailableAttributes($registry);
    $registry->definition($last);
    expect($registry->refreshLoaded())->toBe(0);

    $loads = [$first => 0, $last => 0, $nested => 0];
    $loader = static function (string $name) use ($registry, $first, $last, $nested, &$loads): void {
        if (!isset($loads[$name])) {
            return;
        }
        ++$loads[$name];
        if ($name === $last && $loads[$last] === 2) {
            class_alias(NoConstructor::class, $first);
            $registry->definition($nested);
        } elseif ($name === $nested) {
            class_alias(AttributeCompositionRuleInterface::class, $nested);
        }
    };
    spl_autoload_register($loader, prepend: true);
    try {
        expect($registry->revision)->toBe(2)
            ->and($registry->refreshLoaded())->toBe(2)
            ->and($loads)->toBe([$first => 2, $last => 2, $nested => 1]);
    } finally {
        spl_autoload_unregister($loader);
    }
});
