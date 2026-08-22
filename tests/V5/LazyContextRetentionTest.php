<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use WeakReference;

#[Lazy]
final class AuditLazyRequestRetentionTarget
{
    public function __construct(public ServerRequestInterface $request) {}
}

test('lazy request context is released with the lazy object', function (): void {
    $container = (new ContainerBuilder())->build();
    $request = new ServerRequest('GET', '/lazy-retention');
    $reference = WeakReference::create($request);

    $lazy = $container->make(AuditLazyRequestRetentionTarget::class, [
        'request' => $request,
    ]);

    unset($request);
    gc_collect_cycles();
    expect($reference->get())->not->toBeNull();

    unset($lazy);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
});
