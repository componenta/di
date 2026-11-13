<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeDefinitionAvailability;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

#[Attribute(Attribute::TARGET_ALL)]
final class DeclaredAttribute {}

/** @param class-string $class */
function definitionFor(string $kind, string $class): AttributeDefinition
{
    return match ($kind) {
        'target' => new AttributeDefinition($class),
        'requires' => new AttributeDefinition(DeclaredAttribute::class, requires: [$class]),
        'forbids' => new AttributeDefinition(DeclaredAttribute::class, forbids: [$class]),
        'before' => new AttributeDefinition(DeclaredAttribute::class, before: [$class]),
        'after' => new AttributeDefinition(DeclaredAttribute::class, after: [$class]),
        default => throw new LogicException('Unexpected definition field.'),
    };
}

test('attribute definitions accept a class loaded by the interface fallback probe', function (string $kind): void {
    /** @var class-string $class */
    $class = __NAMESPACE__ . '\\FallbackAttribute_' . bin2hex(random_bytes(5));
    $attempts = 0;
    $loader = static function (string $requested) use ($class, &$attempts): void {
        if ($requested === $class && ++$attempts === 2) {
            class_alias(DeclaredAttribute::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        $definition = definitionFor($kind, $class);
        expect($attempts)->toBe(2)
            ->and($kind === 'target' ? $definition->attribute : $definition->{$kind})
            ->toBe($kind === 'target' ? $class : [$class]);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['target', 'requires', 'forbids', 'before', 'after']);

test('attribute definition fallback preserves missing names and loader failures', function (bool $throws, string $kind): void {
    /** @var class-string $class */
    $class = __NAMESPACE__ . '\\UnavailableAttribute_' . bin2hex(random_bytes(5));
    $attempts = 0;
    $loader = static function (string $requested) use ($class, $throws, &$attempts): void {
        if ($requested === $class && ++$attempts === 2 && $throws) {
            throw new LogicException('Fallback autoload failed.');
        }
    };
    spl_autoload_register($loader);

    try {
        expect(fn() => definitionFor($kind, $class))->toThrow(
            $throws ? LogicException::class : InvalidConfigurationException::class,
            $throws ? 'Fallback autoload failed.' : 'is not available.',
        );
        expect($attempts)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['missing' => false, 'loader failure' => true])->with(['target', 'requires']);
