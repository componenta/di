<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Lazy\LazyContextRetention;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use WeakReference;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Lazy]
final class AuditLazyRequestRetentionTarget
{
    public function __construct(public ServerRequestInterface $request) {}
}

test('lazy request context is released with the lazy object', function (bool $initialize): void {
    $container = (new ContainerBuilder())->build();
    $request = new ServerRequest('GET', '/lazy-retention');
    $reference = WeakReference::create($request);

    $lazy = fixtureInstance($container->make(AuditLazyRequestRetentionTarget::class, [
        'request' => $request,
    ]), AuditLazyRequestRetentionTarget::class);
    $reflection = new ReflectionClass(AuditLazyRequestRetentionTarget::class);

    expect($reflection->isUninitializedLazyObject($lazy))->toBeTrue();

    if ($initialize) {
        expect($lazy->request)->toBe($request)
            ->and($reflection->isUninitializedLazyObject($lazy))->toBeFalse();
    }

    unset($request);
    gc_collect_cycles();
    expect($reference->get())->not->toBeNull();

    unset($lazy);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
})->with(['discarded before initialization' => false, 'discarded after initialization' => true]);
