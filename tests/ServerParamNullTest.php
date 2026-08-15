<?php

declare(strict_types=1);

use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Tests\Fixture\FakeServerRequest;

it('preserves an explicitly present null server parameter instead of using the missing default', function (): void {
    $request = new FakeServerRequest(serverParams: ['nullable' => null]);

    expect((new ServerParam('nullable', default: 'fallback'))->extract($request))->toBeNull();
});
