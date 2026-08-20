<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use WeakReference;

final class AuditExceptionCapturedValue {}

final readonly class AuditInvalidCallableProbe
{
    public function __construct(public object $capture) {}
}

test('failed parameter resolution does not persist closure or request state in the container', function (): void {
    $container = (new ContainerBuilder())->build();
    $captured = new AuditExceptionCapturedValue();
    $request = new ServerRequest('GET', '/');
    $capturedReference = WeakReference::create($captured);
    $requestReference = WeakReference::create($request);
    $closure = static function (string $value) use ($captured): void {
        unset($value, $captured);
    };
    $closureReference = WeakReference::create($closure);

    try {
        $container->call($closure, [ServerRequestInterface::class => $request]);
        test()->fail('Expected parameter resolution to fail.');
    } catch (ResolutionException $error) {
    }

    $diagnostic = [
        $error->parameterName,
        $error->parameterPosition,
        $error->parameterType,
        $error->parameterContext,
        $error->providedParameterTypes,
    ];

    // A live Throwable owns its PHP stack trace. The DI-specific retention
    // contract is that the container and its metadata caches do not retain
    // request/callable state after that Throwable itself is released.
    unset($error, $closure, $request, $captured);
    gc_collect_cycles();

    expect($closureReference->get())->toBeNull()
        ->and($capturedReference->get())->toBeNull()
        ->and($requestReference->get())->toBeNull()
        ->and($diagnostic)->toBe([
            'value',
            0,
            'string',
            'Closure',
            [ServerRequestInterface::class => ServerRequest::class],
        ]);
});

test('invalid callable failures do not retain rejected objects', function (): void {
    $container = (new ContainerBuilder())->build();
    $captured = new AuditExceptionCapturedValue();
    $reference = WeakReference::create($captured);
    $probe = new AuditInvalidCallableProbe($captured);

    try {
        $container->resolve($probe);
        test()->fail('Expected callable resolution to fail.');
    } catch (InvalidCallableException $error) {
    }

    unset($probe, $captured);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($error->callableType)->toBe(AuditInvalidCallableProbe::class)
        ->and($error->callableDescription)->toBe('object ' . AuditInvalidCallableProbe::class);
});
