<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
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

function nestedRequestContextBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    );
}

/** @return array{0: Container, 1: Container, 2: string} */
function nestedRequestContextContainers(): array
{
    $directory = sys_get_temp_dir()
        . '/componenta-nested-request-context-'
        . bin2hex(random_bytes(5));
    $development = nestedRequestContextBuilder()->build();
    $compiler = nestedRequestContextBuilder();
    $compiledFactories = $compiler->compileFactories([
        NestedRequestContextEntry::class,
        NestedRequestContextDto::class,
        NestedRequestContextQueryDto::class,
    ], $directory);
    $configData = $compiler->toArray();
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
    $container = nestedRequestContextBuilder()->build();

    $entry = $container->make(NestedRequestContextEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($entry->dto->value)->toBe('payload-value')
        ->and($entry->dto->request)->toBe($request)
        ->and((string) $entry->dto->uri)->toBe('/orders?q=unused')
        ->and($entry->dto->token)->toBe('header-value')
        ->and($entry->dto->query->q)->toBe('query-value');
});

it('rejects a mapped ServerRequestInterface context key instead of treating it as nested DI context', function (): void {
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
    $container = nestedRequestContextBuilder()->build();

    try {
        $container->make(NestedRequestContextEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->dtoClass)->toBe(NestedRequestContextDto::class)
            ->and($exception->key)->toBe(ServerRequestInterface::class)
            ->and($exception->source)->toBe(ServerRequestInterface::class)
            ->and($exception->parameter)->toBe('request');

        return;
    }

    throw new \RuntimeException('Expected request parameter source conflict.');
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
