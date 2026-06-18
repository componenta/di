<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Resolver\Parameter\NullableResolver;

use function Componenta\DI\Tests\Fixture\typedParam;

describe('Parameter\\NullableResolver', function () {
    it('resolves nullable parameters to [position, null] as a last-resort fallback', function () {
        expect((new NullableResolver())->resolveParameter(typedParam('withNullable', 0)))
            ->toBe([0, null]);
    });

    it('resolves nullable class-typed parameters to null', function () {
        expect((new NullableResolver())->resolveParameter(typedParam('withNullable', 1)))
            ->toBe([1, null]);
    });

    it('returns null (no match) for non-nullable parameters', function () {
        expect((new NullableResolver())->resolveParameter(typedParam('primitives', 0)))
            ->toBeNull();
    });

    it('treats untyped parameters as nullable (PHP semantics: untyped allows null)', function () {
        expect((new NullableResolver())->resolveParameter(typedParam('untyped', 0)))
            ->toBe([0, null]);
    });

    it('compiles and resolves a nullable payload', function () {
        $resolver = new NullableResolver();
        $param = typedParam('withNullable', 0);

        $payload = $resolver->compilePayload($param);

        expect($payload)->toBeTrue()
            ->and($resolver->resolveParameterPlan($param, $payload))->toBe([0, null]);
    });

    it('falls back to reflection when the nullable payload is invalid', function () {
        $resolver = new NullableResolver();
        $param = typedParam('withNullable', 0);

        expect($resolver->resolveParameterPlan($param, null))->toBe([0, null]);
    });
});
