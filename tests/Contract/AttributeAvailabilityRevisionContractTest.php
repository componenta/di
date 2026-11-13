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
use LogicException;
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
        expect(fn(): int => $registry->revision)->toThrow($failure);
        expect($loads)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
});
