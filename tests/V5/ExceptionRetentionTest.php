<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use WeakReference;

final class AuditExceptionCapturedValue {}

final readonly class AuditInvalidCallableProbe
{
    public function __construct(public object $capture) {}
}

final class AuditFailedParameterRetentionProbe implements ParameterResolverInterface
{
    /** @var WeakReference<ParameterTarget>|null */
    public ?WeakReference $target = null;
    /** @var WeakReference<\ReflectionParameter>|null */
    public ?WeakReference $reflection = null;

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $this->target = WeakReference::create($target);
        $this->reflection = WeakReference::create($target->reflection);
        return null;
    }
}

test('failed parameter resolution does not persist closure or request state in the container', function (): void {
    $probe = new AuditFailedParameterRetentionProbe();
    $container = (new ContainerBuilder())
        ->addParameterResolver($probe, 2000)
        ->build();
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

    unset($error, $closure, $request, $captured);
    gc_collect_cycles();

    expect($probe->target?->get())->toBeNull()
        ->and($probe->reflection?->get())->toBeNull()
        ->and($closureReference->get())->toBeNull()
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

test('failed callable resolution does not persist rejected objects in the container', function (): void {
    $container = (new ContainerBuilder())->build();
    $captured = new AuditExceptionCapturedValue();
    $reference = WeakReference::create($captured);
    $probe = new AuditInvalidCallableProbe($captured);

    try {
        $container->resolve($probe);
        test()->fail('Expected callable resolution to fail.');
    } catch (InvalidCallableException $error) {
    }

    $diagnostic = [$error->callableType, $error->callableDescription];
    unset($error, $probe, $captured);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($diagnostic)->toBe([
            AuditInvalidCallableProbe::class,
            'object ' . AuditInvalidCallableProbe::class,
        ]);
});
