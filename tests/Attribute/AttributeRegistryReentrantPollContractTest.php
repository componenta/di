<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributeRegistryReentrantPollContract;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Internal\AttributeRegistryAccess;

test('one registry poll notices an earlier name declared by a later loader', function (bool $interface): void {
    $suffix = bin2hex(random_bytes(6));
    /** @var class-string $earlier */
    $earlier = __NAMESPACE__ . '\\Earlier_' . $suffix;
    /** @var class-string $later */
    $later = __NAMESPACE__ . '\\Later_' . $suffix;
    /** @var class-string $missing */
    $missing = __NAMESPACE__ . '\\Missing_' . $suffix;
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($earlier);
    $registry->definition($later);
    $registry->definition($missing);
    $before = AttributeRegistryAccess::refreshAvailability($registry);
    $loads = [$earlier => 0, $later => 0, $missing => 0];
    $loader = static function (string $requested) use ($earlier, $later, $interface, &$loads): void {
        if (array_key_exists($requested, $loads)) {
            ++$loads[$requested];
        }
        if ($requested === $later) {
            eval(sprintf(
                'namespace %s; %s %s {} final class %s {}',
                __NAMESPACE__,
                $interface ? 'interface' : 'final class',
                substr($earlier, strrpos($earlier, '\\') + 1),
                substr($later, strrpos($later, '\\') + 1),
            ));
        }
    };
    spl_autoload_register($loader);
    try {
        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before + 2)
            ->and(class_exists($earlier, false) || interface_exists($earlier, false))->toBeTrue()
            ->and($loads)->toBe([$earlier => 2, $later => 1, $missing => 2]);

        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before + 2)
            ->and($loads)->toBe([$earlier => 2, $later => 1, $missing => 4]);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['class declaration' => false, 'interface declaration' => true]);

test('one registry poll checks a name added reentrantly after its loader becomes ready', function (bool $interface): void {
    $suffix = bin2hex(random_bytes(6));
    /** @var class-string $appended */
    $appended = __NAMESPACE__ . '\\Appended_' . $suffix;
    /** @var class-string $trigger */
    $trigger = __NAMESPACE__ . '\\Trigger_' . $suffix;
    /** @var class-string $missing */
    $missing = __NAMESPACE__ . '\\Missing_' . $suffix;
    $registry = new AttributeDefinitionRegistry();
    $registry->definition($missing);
    $registry->definition($trigger);
    $before = AttributeRegistryAccess::refreshAvailability($registry);
    $ready = false;
    $trackedBeforeReady = false;
    $loads = [$missing => 0, $trigger => 0, $appended => 0];
    $loader = static function (string $requested) use ($registry, $trigger, $appended, $interface, &$ready, &$trackedBeforeReady, &$loads): void {
        if (array_key_exists($requested, $loads)) {
            ++$loads[$requested];
        }
        if ($requested === $trigger) {
            $trackedBeforeReady = $registry->definition($appended) === null;
            $ready = true;
            eval(sprintf('namespace %s; final class %s {}', __NAMESPACE__, substr($trigger, strrpos($trigger, '\\') + 1)));
        } elseif ($requested === $appended && $ready) {
            eval(sprintf('namespace %s; %s %s {}', __NAMESPACE__, $interface ? 'interface' : 'final class', substr($appended, strrpos($appended, '\\') + 1)));
        }
    };
    spl_autoload_register($loader);
    try {
        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before + 2)
            ->and($trackedBeforeReady)->toBeTrue()
            ->and(class_exists($appended, false) || interface_exists($appended, false))->toBeTrue()
            ->and($loads)->toBe([$missing => 2, $trigger => 1, $appended => 1]);

        expect(AttributeRegistryAccess::refreshAvailability($registry))->toBe($before + 2)
            ->and($loads)->toBe([$missing => 4, $trigger => 1, $appended => 1]);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['class declaration' => false, 'interface declaration' => true]);
