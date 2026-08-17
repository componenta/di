<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final readonly class NestedRequestContextQueryDto
{
    public function __construct(public string $q) {}
}

final readonly class NestedRequestContextDto
{
    public function __construct(
        public string $value,
        public ServerRequestInterface $request,
        public UriInterface $uri,
        #[Header('X-Token')]
        public string $token,
        #[MapQueryString]
        public NestedRequestContextQueryDto $query,
    ) {}
}

final readonly class NestedRequestContextEntry
{
    public function __construct(
        #[MapRequestPayload]
        public NestedRequestContextDto $dto,
    ) {}
}

/** @return array{0: Container, 1: Container, 2: string} */
function nestedRequestContextContainers(): array
{
    $directory = sys_get_temp_dir()
        . '/componenta-nested-request-context-'
        . bin2hex(random_bytes(5));
    $provider = new ConfigProvider();
    $configData = $provider();
    $development = ContainerBuilder::configure(new Config($configData))->build();
    $compiledFactories = ContainerBuilder::configure(new Config($configData))
        ->compileFactories([
            NestedRequestContextEntry::class,
            NestedRequestContextDto::class,
            NestedRequestContextQueryDto::class,
        ], $directory);

    $dependencies = $configData[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $compiledFactories,
    );

    $production = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
        $directory,
    )->build();

    return [$development, $production, $directory];
}

function cleanupNestedRequestContextDirectory(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

/** @return array<string, mixed> */
function nestedRequestContextSnapshot(
    Container $container,
    ServerRequestInterface $request,
): array {
    $entry = $container->make(NestedRequestContextEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    return [
        'value' => $entry->dto->value,
        'request' => $entry->dto->request,
        'uri' => (string) $entry->dto->uri,
        'token' => $entry->dto->token,
        'query' => $entry->dto->query->q,
    ];
}

it('propagates the trusted PSR-7 request through nested request DTO construction', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        uri: '/orders?q=unused',
        queryParams: ['q' => 'query-value'],
        parsedBody: ['value' => 'payload-value'],
    ))->withHeader('X-Token', 'header-value');
    $container = ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->build();

    $entry = $container->make(NestedRequestContextEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($entry->dto->value)->toBe('payload-value')
        ->and($entry->dto->request)->toBe($request)
        ->and((string) $entry->dto->uri)->toBe('/orders?q=unused')
        ->and($entry->dto->token)->toBe('header-value')
        ->and($entry->dto->query->q)->toBe('query-value');
});

it('overwrites a mapped ServerRequestInterface context key with the trusted request', function (): void {
    $spoofed = new FakeServerRequest(
        method: 'POST',
        uri: '/spoofed',
        queryParams: ['q' => 'spoofed-query'],
    );
    $request = (new FakeServerRequest(
        method: 'POST',
        uri: '/trusted',
        queryParams: ['q' => 'trusted-query'],
        parsedBody: [
            'value' => 'payload-value',
            ServerRequestInterface::class => $spoofed,
        ],
    ))->withHeader('X-Token', 'trusted-header');
    $container = ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->build();

    $entry = $container->make(NestedRequestContextEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($entry->dto->request)->toBe($request)
        ->and((string) $entry->dto->uri)->toBe('/trusted')
        ->and($entry->dto->token)->toBe('trusted-header')
        ->and($entry->dto->query->q)->toBe('trusted-query');
});

it('keeps nested request context identical in reflection and compiled factories', function (): void {
    [$development, $production, $directory] = nestedRequestContextContainers();
    $request = (new FakeServerRequest(
        method: 'POST',
        uri: '/compiled',
        queryParams: ['q' => 'compiled-query'],
        parsedBody: ['value' => 'compiled-payload'],
    ))->withHeader('X-Token', 'compiled-header');

    try {
        $expected = nestedRequestContextSnapshot($development, $request);
        $actual = nestedRequestContextSnapshot($production, $request);

        expect($actual)->toBe($expected)
            ->and($actual['request'])->toBe($request);
    } finally {
        cleanupNestedRequestContextDirectory($directory);
    }
});
