<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;

it('round-trips caller-visible mapped context without exposing DI provenance', function (): void {
    $visible = [
        'value' => 'payload-value',
        PHP_INT_MIN => 'caller-owned',
    ];
    $transport = MappedRequestContext::with($visible, ['value' => 'payload-value']);

    expect(MappedRequestContext::get($transport))->not->toBeNull()
        ->and(MappedRequestContext::strip($transport))->toBe($visible);
});
