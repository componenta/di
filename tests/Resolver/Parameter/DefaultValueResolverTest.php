<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Resolver\Parameter\DefaultValueResolver;

use function Componenta\DI\Tests\Fixture\typedParam;

describe('Parameter\\DefaultValueResolver', function () {
    it('resolves parameters with a declared default to [position, default]', function () {
        expect((new DefaultValueResolver())->resolveParameter(typedParam('withDefaults', 0)))
            ->toBe([0, 1]);

        expect((new DefaultValueResolver())->resolveParameter(typedParam('withDefaults', 1)))
            ->toBe([1, 'asc']);
    });

    it('returns null when the parameter has no default value', function () {
        expect((new DefaultValueResolver())->resolveParameter(typedParam('primitives', 0)))
            ->toBeNull();
    });

    it('ignores provided parameters - it only reads the declared default', function () {
        $param = typedParam('withDefaults', 0); // $page = 1

        expect((new DefaultValueResolver())->resolveParameter($param, ['page' => 99]))
            ->toBe([0, 1]);
    });

    it('compiles and resolves a declared default payload', function () {
        $resolver = new DefaultValueResolver();
        $param = typedParam('withDefaults', 1);

        $payload = $resolver->compilePayload($param);

        expect($payload)->toBe(['value' => 'asc'])
            ->and($resolver->resolveParameterPlan($param, $payload))->toBe([1, 'asc']);
    });

    it('falls back to reflection when the default payload is invalid', function () {
        $resolver = new DefaultValueResolver();
        $param = typedParam('withDefaults', 0);

        expect($resolver->resolveParameterPlan($param, null))->toBe([0, 1]);
    });
});
