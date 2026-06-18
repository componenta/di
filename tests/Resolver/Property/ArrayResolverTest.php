<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Resolver\Property\ArrayResolver;
use Componenta\DI\Tests\Fixture\TypedProperties;

use function Componenta\DI\Tests\Fixture\typedProperty;

describe('Property\\ArrayResolver', function () {
    it('returns null when no context value matches the property name', function () {
        $property = typedProperty(TypedProperties::class, 'name');

        expect((new ArrayResolver())->resolveProperty($property, []))->toBeNull();
    });

    it('returns [property, value] for a matching context key', function () {
        $property = typedProperty(TypedProperties::class, 'name');

        $result = (new ArrayResolver())->resolveProperty($property, ['name' => 'Alice']);

        expect($result)->toBe([$property, 'Alice']);
    });

    it('treats a context key set to null as a match (array_key_exists semantics)', function () {
        $property = typedProperty(TypedProperties::class, 'name');

        $result = (new ArrayResolver())->resolveProperty($property, ['name' => null]);

        expect($result)->toBe([$property, null]);
    });

    it('does not use non-matching keys even when present', function () {
        $property = typedProperty(TypedProperties::class, 'count');

        expect((new ArrayResolver())->resolveProperty($property, ['other' => 99]))->toBeNull();
    });
});
