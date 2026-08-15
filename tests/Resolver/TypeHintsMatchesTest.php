<?php

declare(strict_types=1);

use Componenta\DI\Resolver\TypeHints;

it('accepts an integer for a float declaration like PHP does', function (): void {
    $parameter = (new ReflectionFunction(
        static fn(float $value): float => $value,
    ))->getParameters()[0];

    expect(TypeHints::matches($parameter->getType(), 1))->toBeTrue()
        ->and(TypeHints::matches($parameter->getType(), 1.5))->toBeTrue()
        ->and(TypeHints::matches($parameter->getType(), '1'))->toBeFalse();
});
