<?php

declare(strict_types=1);

use Componenta\DI\Tests\Fixture\ConfigurableQueryMapper;

it('rejects a non-scalar sort alias with a stable mapping exception', function (): void {
    $mapper = new ConfigurableQueryMapper(
        sortMap: ['newest' => ['createdAt' => 'desc']],
    );

    expect(fn() => $mapper->transform(['sort' => ['unexpected']]))
        ->toThrow(
            InvalidArgumentException::class,
            'Sort alias must be a string or integer',
        );
});

it('rejects an explicitly null sort alias instead of treating it as missing', function (): void {
    $mapper = new ConfigurableQueryMapper(
        sortMap: ['newest' => ['createdAt' => 'desc']],
    );

    expect(fn() => $mapper->transform(['sort' => null]))
        ->toThrow(
            InvalidArgumentException::class,
            'Sort alias must be a string or integer',
        );
});
