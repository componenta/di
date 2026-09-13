<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompositionConfiguration;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class First {}
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Second {}
interface Capability extends AttributeCapabilityInterface {}

test('unavailable attribute definitions fail during declaration', function (): void {
    // @phpstan-ignore argument.type (Deliberately unavailable class at the public configuration boundary.)
    expect(fn() => new AttributeDefinition(__NAMESPACE__ . '\\Unavailable'))
        ->toThrow(InvalidConfigurationException::class, 'is not available');
});

test('attribute definitions reject capabilities outside the capability contract', function (): void {
    // @phpstan-ignore argument.type (Deliberately invalid capability.)
    expect(fn() => new AttributeDefinition(First::class, capabilities: [\stdClass::class]))
        ->toThrow(InvalidConfigurationException::class, AttributeCapabilityInterface::class);
});

test('attribute definitions reject invalid composition rule objects', function (): void {
    // @phpstan-ignore argument.type (Deliberately invalid rule.)
    expect(fn() => new AttributeDefinition(First::class, rules: [new \stdClass()]))
        ->toThrow(InvalidConfigurationException::class, 'Composition rule');
});

test('unavailable composition selectors fail during declaration', function (string $kind, array $selectors): void {
    expect(fn() => new AttributeDefinition(
        First::class,
        // @phpstan-ignore argument.type (Deliberately unavailable selectors at the public boundary.)
        requires: $kind === 'requires' ? $selectors : [],
        // @phpstan-ignore argument.type (Deliberately unavailable selectors at the public boundary.)
        forbids: $kind === 'forbids' ? $selectors : [],
        // @phpstan-ignore argument.type (Deliberately unavailable selectors at the public boundary.)
        before: $kind === 'before' ? $selectors : [],
        // @phpstan-ignore argument.type (Deliberately unavailable selectors at the public boundary.)
        after: $kind === 'after' ? $selectors : [],
    ))->toThrow(InvalidConfigurationException::class, 'in ' . $kind . ' is not available');
})->with(array_map(static fn(string $kind): array => [$kind, [__NAMESPACE__ . '\\UnavailableSelector']], ['requires', 'forbids', 'before', 'after']));

test('capability policies reject unrelated capability classes', function (): void {
    // @phpstan-ignore argument.type (Deliberately invalid capability.)
    expect(fn() => new CapabilityPolicy(\stdClass::class))
        ->toThrow(InvalidConfigurationException::class, AttributeCapabilityInterface::class);
});

test('capability policies reject nonpositive limits', function (int $limit): void {
    expect(fn() => new CapabilityPolicy(Capability::class, $limit))
        ->toThrow(InvalidConfigurationException::class, 'must be null or at least 1');
})->with([0, -1, PHP_INT_MIN]);

test('custom capability limits are enforced before invoking the target', function (?int $limit): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(First::class, capabilities: [Capability::class]))
        ->addAttributeDefinition(new AttributeDefinition(Second::class, capabilities: [Capability::class]))
        ->defineAttributeCapability(new CapabilityPolicy(Capability::class, $limit))
        ->build();
    $calls = new class () {
        public int $count = 0;
    };
    $target = static function (#[First, Second] string $value) use ($calls): string {
        ++$calls->count;
        return $value;
    };
    if ($limit === 1) {
        expect(fn() => $container->call($target, ['value' => 'accepted']))
            ->toThrow(AttributeCompositionException::class, 'accepts at most 1 attribute(s)')
            ->and($calls->count)->toBe(0);
    } else {
        expect($container->call($target, ['value' => 'accepted']))->toBe('accepted')
            ->and($calls->count)->toBe(1);
    }
})->with([null, 1, 2, PHP_INT_MAX]);
