<?php

declare(strict_types=1);

use Componenta\DI\CallableInvoker;
use Componenta\DI\Exception\InvalidCallableException;

it('rejects a non-callable value before invoking the PHP engine', function (): void {
    expect(fn() => (new CallableInvoker())->call(123))
        ->toThrow(InvalidCallableException::class, 'Cannot convert value');
});
