<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributeAvailabilityRevisionContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Internal\AttributeRegistryAccess;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use ReflectionClass;
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

test('availability observes a class declared during the same autoload poll', function (bool $customRule, string $form, int $readyOnAttempt): void {
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
    if ($customRule) {
        $builder->addAttributeDefinition(new AttributeDefinition(UnusedMarker::class, rules: [new NoOpRule()]));
    }
    [$container, $runtime] = $builder->assemble();
    ConstructionCounter::$calls = 0;
    expect($container->has($class))->toBeFalse()
        ->and(class_exists($attribute, false))->toBeFalse();

    $reflection = new ReflectionClass($class);
    $pendingPlan = $runtime->plans->build($reflection);

    expect($runtime->plans->build($reflection) === $pendingPlan)->toBe(!$customRule);

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
})->with(['default discovery' => false, 'revalidate pending declarations' => true])
    ->with(['class declaration' => 'class', 'class alias' => 'alias'])
    ->with(['first attempt' => 1, 'fallback attempt' => 2]);

test('availability revision reflects every declaration discovered by the current poll', function (string $form): void {
    $shortName = 'LateDeclaration_' . bin2hex(random_bytes(6));
    /** @var class-string $attribute */
    $attribute = __NAMESPACE__ . '\\' . $shortName;
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($attribute);
    $before = AttributeRegistryAccess::refreshAvailability($registry);
    $loads = 0;
    $loader = static function (string $requested) use ($attribute, $shortName, $form, &$loads): void {
        if ($requested === $attribute && ++$loads === 2) {
            declareLateAttribute($shortName, $form);
        }
    };

    spl_autoload_register($loader);
    try {
        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before + 1)
            ->and(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before + 1)
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
    $before = AttributeRegistryAccess::refreshAvailability($registry);
    $loads = 0;
    $loader = static function (string $requested) use ($attribute, &$loads): void {
        if ($requested === $attribute) {
            ++$loads;
        }
    };

    spl_autoload_register($loader);
    try {
        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before)->and($loads)->toBe(2);
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
            $revision = AttributeRegistryAccess::refreshAvailability($registry);
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
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: true);
    addUnavailableAttributes($registry);
    $shortName = 'FiberDeclaration_' . bin2hex(random_bytes(6));
    /** @var class-string $attribute */
    $attribute = __NAMESPACE__ . '\\' . $shortName;
    /** @var class-string&lowercase-string $lower */
    $lower = strtolower($attribute);
    $registry->definition($attribute);
    $registry->definition($lower);

    expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe(0);
    $fiber = new Fiber(static function () use ($registry): int {
        Fiber::suspend(AttributeRegistryAccess::refreshAvailability($registry));
        return AttributeRegistryAccess::refreshAvailability($registry);
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
            ->and(AttributeRegistryAccess::refreshAvailability($registry))->toBe(2)
            ->and($loads)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['class', 'interface', 'alias']);

test('loaded-only availability tracks aliases that PHP omits from its declaration lists', function (): void {
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: true);
    addUnavailableAttributes($registry);
    expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe(0);

    /** @var class-string $attribute */
    $attribute = "\0Optional_" . bin2hex(random_bytes(6));
    $registry->definition($attribute);
    expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe(0);

    $aliased = class_alias(UnusedMarker::class, $attribute, autoload: false);
    expect($aliased)->toBeTrue()
        ->and(array_map(strtolower(...), get_declared_classes()))->not->toContain(strtolower($attribute));

    $revisions = [AttributeRegistryAccess::refreshAvailability($registry), AttributeRegistryAccess::refreshAvailability($registry)];

    expect($revisions)->toBe([1, 1]);
});

test('targeted definition lookup releases the declaration snapshot with the last pending name', function (): void {
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: true);
    $attributes = addUnavailableAttributes($registry);
    $definitions = new ReflectionProperty($registry, 'definitions')->getValue($registry);
    if (!is_object($definitions)) {
        throw new \LogicException('Expected the registry definition state.');
    }
    $snapshot = new ReflectionProperty($definitions, 'checkedDeclarations');
    expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe(0)->and($snapshot->getValue($definitions))->toBeArray();

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

    expect($snapshot->getValue($definitions))->toBeNull()->and(AttributeRegistryAccess::refreshAvailability($registry))->toBe(count($attributes));
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
    expect(AttributeRegistryAccess::refreshLoaded($registry))->toBe(0);

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
        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe(2)
            ->and(AttributeRegistryAccess::refreshLoaded($registry))->toBe(2)
            ->and($loads)->toBe([$first => 2, $last => 2, $nested => 1]);
    } finally {
        spl_autoload_unregister($loader);
    }
});
