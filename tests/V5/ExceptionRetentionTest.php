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

test('resolution failures release closure captures and request objects after the public call completes', function (): void {
    $container = (new ContainerBuilder())->build();
    $captured = new AuditExceptionCapturedValue();
    $request = new ServerRequest('GET', '/');
    $capturedReference = WeakReference::create($captured);
    $requestReference = WeakReference::create($request);
    $closure = static function (string $value) use ($captured): void {
        unset($value, $captured);
    };

    try {
        $container->call($closure, [ServerRequestInterface::class => $request]);
        test()->fail('Expected parameter resolution to fail.');
    } catch (ResolutionException $error) {
    }

    unset($closure, $request, $captured);
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
