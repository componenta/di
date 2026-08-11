<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;

function runCollisionMapping(array $data, array $map): array
{
    return (new RequestMapperPipeline())->run(
        data: $data,
        map: $map,
        defaults: [],
        cast: [],
        sortMap: [],
        exclude: [],
        provider: new NullCasterProvider(),
    );
}

it('reads chained mappings from the original input', function (): void {
    expect(runCollisionMapping(
        ['a' => 1, 'b' => 2],
        ['a' => 'b', 'b' => 'c'],
    ))->toBe(['b' => 1, 'c' => 2]);
});

it('supports atomic field swaps', function (): void {
    expect(runCollisionMapping(
        ['a' => 1, 'b' => 2],
        ['a' => 'b', 'b' => 'a'],
    ))->toBe(['b' => 1, 'a' => 2]);
});

it('rejects overwriting an unmapped input field', function (): void {
    expect(fn() => runCollisionMapping(
        ['a' => 1, 'c' => 3],
        ['a' => 'c'],
    ))->toThrow(InvalidArgumentException::class, 'already exists in input');
});

it('rejects two source fields mapped to one target', function (): void {
    expect(fn() => runCollisionMapping(
        ['a' => 1, 'b' => 2],
        ['a' => 'c', 'b' => 'c'],
    ))->toThrow(InvalidArgumentException::class, 'produced by both');
});
