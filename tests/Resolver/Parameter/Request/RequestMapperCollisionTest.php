<?php

declare(strict_types=1);

use Componenta\DI\Tests\Fixture\ConfigurableQueryMapper;

it('reads chained mappings from the original input', function (): void {
    $mapper = new ConfigurableQueryMapper(map: ['a' => 'b', 'b' => 'c']);

    expect($mapper->transform(['a' => 1, 'b' => 2]))
        ->toBe(['b' => 1, 'c' => 2]);
});

it('supports atomic field swaps', function (): void {
    $mapper = new ConfigurableQueryMapper(map: ['a' => 'b', 'b' => 'a']);

    expect($mapper->transform(['a' => 1, 'b' => 2]))
        ->toBe(['b' => 1, 'a' => 2]);
});

it('rejects overwriting an unmapped input field', function (): void {
    $mapper = new ConfigurableQueryMapper(map: ['a' => 'c']);

    expect(fn() => $mapper->transform(['a' => 1, 'c' => 3]))
        ->toThrow(InvalidArgumentException::class, 'already exists in input');
});

it('rejects two source fields mapped to one target', function (): void {
    $mapper = new ConfigurableQueryMapper(map: ['a' => 'c', 'b' => 'c']);

    expect(fn() => $mapper->transform(['a' => 1, 'b' => 2]))
        ->toThrow(InvalidArgumentException::class, 'produced by both');
});
