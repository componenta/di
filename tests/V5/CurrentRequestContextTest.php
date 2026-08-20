<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final readonly class CurrentRequestAotTarget
{
    public function __construct(
        #[CurrentRequest]
        public ServerRequestInterface $request,
        #[CurrentUri]
        public UriInterface $uri,
    ) {}
}

test('current request attributes resolve from the HTTP context transport', function (): void {
    $container = (new ContainerBuilder())->build();
    $request = new ServerRequest('GET', 'https://example.test/orders?page=2');

    $resolved = $container->call(
        static fn(
            #[CurrentRequest] ServerRequestInterface $currentRequest,
            #[CurrentUri] UriInterface $currentUri,
        ): array => [$currentRequest, $currentUri],
        [ServerRequestInterface::class => $request],
    );

    expect($resolved[0])->toBe($request)
        ->and($resolved[1])->toBe($request->getUri());
});

test('current request attributes are authoritative over generic caller parameters', function (): void {
    $container = (new ContainerBuilder())->build();
    $current = new ServerRequest('GET', 'https://example.test/current');
    $otherRequest = new ServerRequest('GET', 'https://example.test/other');
    $otherUri = new Uri('https://example.test/explicit-uri');

    $resolved = $container->call(
        static fn(
            #[CurrentRequest] ServerRequestInterface $request,
            #[CurrentUri] UriInterface $uri,
        ): array => [$request, $uri],
        [
            ServerRequestInterface::class => $current,
            'request' => $otherRequest,
            'uri' => $otherUri,
        ],
    );

    expect($resolved[0])->toBe($current)
        ->and($resolved[1])->toBe($current->getUri());
});

test('current request attributes fail when no HTTP context is available', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->call(
        static fn(#[CurrentRequest] ServerRequestInterface $request): ServerRequestInterface => $request,
    ))->toThrow(ResolutionException::class, 'PSR-7 request is required for #[CurrentRequest]')
        ->and(fn() => $container->call(
            static fn(#[CurrentUri] UriInterface $uri): UriInterface => $uri,
        ))->toThrow(ResolutionException::class, 'PSR-7 request is required for #[CurrentUri]');
});

test('bare request and URI types do not imply the current HTTP context', function (): void {
    $container = (new ContainerBuilder())->build();
    $current = new ServerRequest('GET', 'https://example.test/current');
    $explicit = new ServerRequest('GET', 'https://example.test/explicit');

    expect(fn() => $container->call(
        static fn(ServerRequestInterface $request): ServerRequestInterface => $request,
        [ServerRequestInterface::class => $current],
    ))->toThrow(ResolutionException::class)
        ->and(fn() => $container->call(
            static fn(UriInterface $uri): UriInterface => $uri,
            [ServerRequestInterface::class => $current],
        ))->toThrow(ResolutionException::class)
        ->and($container->call(
            static fn(ServerRequestInterface $request): ServerRequestInterface => $request,
            [
                ServerRequestInterface::class => $current,
                'request' => $explicit,
            ],
        ))->toBe($explicit);
});

test('current request attributes keep reflection and AOT semantics identical', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-current-request-' . bin2hex(random_bytes(5));
    $builder = new ContainerBuilder();
    $development = $builder->build();
    $request = new ServerRequest('GET', 'https://example.test/aot');

    try {
        $compiled = $builder->compileFactories([CurrentRequestAotTarget::class], $directory);
        $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );
        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();

        $params = [ServerRequestInterface::class => $request];
        $dev = $development->make(CurrentRequestAotTarget::class, $params);
        $prod = $production->make(CurrentRequestAotTarget::class, $params);

        expect($dev->request)->toBe($request)
            ->and($dev->uri)->toBe($request->getUri())
            ->and($prod->request)->toBe($request)
            ->and($prod->uri)->toBe($request->getUri());
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
