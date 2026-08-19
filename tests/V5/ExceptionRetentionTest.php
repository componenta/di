<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use WeakReference;

final class AuditExceptionCapturedValue {}

test('resolution exceptions detach parameter diagnostics from closure and request objects', function (): void {
    $captured = new AuditExceptionCapturedValue();
    $request = new ServerRequest('GET', '/');
    $capturedReference = WeakReference::create($captured);
    $requestReference = WeakReference::create($request);
    $closure = static function (string $value) use ($captured): void {
        unset($value, $captured);
    };
    $reflection = new ReflectionFunction($closure);
    $parameter = $reflection->getParameters()[0];

    $error = ResolutionException::forParameter(
        $parameter,
        providedParameters: [ServerRequestInterface::class => $request],
    );

    unset($parameter, $reflection, $closure, $request, $captured);
    gc_collect_cycles();

    expect($capturedReference->get())->toBeNull()
        ->and($requestReference->get())->toBeNull()
        ->and($error->parameterName)->toBe('value')
        ->and($error->parameterPosition)->toBe(0)
        ->and($error->parameterType)->toBe('string')
        ->and($error->parameterContext)->toBe('Closure')
        ->and($error->providedParameterTypes)->toBe([
            ServerRequestInterface::class => ServerRequest::class,
        ]);
});

test('invalid callable exceptions detach diagnostics from closure captures', function (): void {
    $captured = new AuditExceptionCapturedValue();
    $reference = WeakReference::create($captured);
    $closure = static function () use ($captured): void {
        unset($captured);
    };

    $error = InvalidCallableException::forValue($closure);

    unset($closure, $captured);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($error->callableType)->toBe('Closure')
        ->and($error->callableDescription)->toBe('Closure');
});
