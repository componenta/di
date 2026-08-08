<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\AliasResolver;
use Componenta\DI\Exception\CircularDependencyException;

it('rejects an existing malformed alias cycle during a later update', function () {
    $aliases = new AliasResolver([
        'cycle.a' => 'cycle.b',
        'cycle.b' => 'cycle.a',
    ], skipValidation: true);

    expect(fn() => $aliases->set('unrelated', 'cycle.a'))
        ->toThrow(CircularDependencyException::class);
});
