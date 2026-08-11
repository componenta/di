<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;

it('rejects a non-scalar sort alias with a stable mapping exception', function (): void {
    expect(fn () => (new RequestMapperPipeline())->run(
        data: ['sort' => ['unexpected']],
        map: [],
        defaults: [],
        cast: [],
        sortMap: ['newest' => ['createdAt' => 'desc']],
        exclude: [],
        provider: new NullCasterProvider(),
    ))->toThrow(
        InvalidArgumentException::class,
        'Sort alias must be a string or integer',
    );
});
