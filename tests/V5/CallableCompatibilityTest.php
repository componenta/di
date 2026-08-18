<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Header;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class CallableDependency {}

test('CallableExecutor keeps the v4 DI-aware call convenience API', function (): void {
    $container = (new ContainerBuilder())->build();
    $executor = $container->get(CallableExecutorInterface::class);

    expect($executor)->toBeInstanceOf(CallableInvokerInterface::class)
        ->and($executor->call(
            static fn(CallableDependency $dependency): CallableDependency => $dependency,
        ))->toBeInstanceOf(CallableDependency::class)
        ->and($executor->call(
            static fn(int $left, int $right): int => $left - $right,
            ['left' => 10, 'right' => 3],
        ))->toBe(7);
});

test('container CallableInvokerInterface remains DI-aware as in v4', function (): void {
    $container = (new ContainerBuilder())->build();
    $invoker = $container->get(CallableInvokerInterface::class);

    expect($invoker)->toBe($container)
        ->and($container)->toBeInstanceOf(Container::class)
        ->and($invoker->call(
            static fn(CallableDependency $dependency): CallableDependency => $dependency,
        ))->toBeInstanceOf(CallableDependency::class);
});

test('legacy call request transport is promoted to trusted v5 provenance', function (): void {
    $request = (new ServerRequest('GET', '/orders/17'))->withHeader('X-Token', 'trusted');
    $container = (new ContainerBuilder())->build();

    $result = $container->call(
        static fn(
            #[Header('X-Token')] string $token,
            UriInterface $uri,
            ServerRequestInterface $resolvedRequest,
        ): array => [$token, (string) $uri, $resolvedRequest],
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe(['trusted', '/orders/17', $request]);
});
