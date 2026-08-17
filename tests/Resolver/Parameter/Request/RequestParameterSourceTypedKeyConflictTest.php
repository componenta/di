<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

interface TypedKeySourceContract {}

final readonly class TypedKeySourceValue implements TypedKeySourceContract {}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class TypedKeyParameterSource implements ParameterSourceAttributeInterface {}

final readonly class TypedKeySourceDto
{
    public function __construct(
        #[TypedKeyParameterSource]
        public TypedKeySourceContract $service,
    ) {}
}

final readonly class TypedKeySourceEntry
{
    public function __construct(
        #[MapRequestPayload]
        public TypedKeySourceDto $dto,
    ) {}
}

final readonly class TypedKeyRequestDto
{
    public function __construct(public ServerRequestInterface $request) {}
}

final readonly class TypedKeyRequestEntry
{
    public function __construct(
        #[MapRequestPayload]
        public TypedKeyRequestDto $dto,
    ) {}
}

final readonly class TypedKeyUriDto
{
    public function __construct(public UriInterface $uri) {}
}

final readonly class TypedKeyUriEntry
{
    public function __construct(
        #[MapRequestPayload]
        public TypedKeyUriDto $dto,
    ) {}
}

function typedKeySourceBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    );
}

/** @return array{0: Container, 1: Container, 2: string} */
function typedKeySourceContainers(): array
{
    $directory = sys_get_temp_dir()
        . '/componenta-typed-key-source-conflict-'
        . bin2hex(random_bytes(5));
    $development = typedKeySourceBuilder()->build();
    $compiler = typedKeySourceBuilder();
    $compiledFactories = $compiler->compileFactories([
        TypedKeySourceEntry::class,
        TypedKeySourceDto::class,
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

function cleanupTypedKeySourceDirectory(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

/** @return array{class-string, string, class-string, string} */
function typedKeySourceConflictSnapshot(
    Container $container,
    ServerRequestInterface $request,
): array {
    try {
        $container->make(TypedKeySourceEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        return [
            $exception::class,
            $exception->key,
            $exception->source,
            $exception->parameter ?? '',
        ];
    }

    throw new \RuntimeException('Expected request parameter source conflict.');
}

it('rejects mapped type-key data that could shadow a source-bound object parameter', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: [TypedKeySourceContract::class => new TypedKeySourceValue()],
    );

    try {
        typedKeySourceBuilder()->build()->make(TypedKeySourceEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe(TypedKeySourceContract::class)
            ->and($exception->source)->toBe(TypedKeyParameterSource::class)
            ->and($exception->parameter)->toBe('service');

        return;
    }

    throw new \RuntimeException('Expected request parameter source conflict.');
});

it('rejects mapped type-key data for trusted request and uri context parameters', function (): void {
    $container = typedKeySourceBuilder()->build();
    $spoofedRequest = new FakeServerRequest(uri: '/spoofed');
    $requestCollision = new FakeServerRequest(
        method: 'POST',
        parsedBody: [ServerRequestInterface::class => $spoofedRequest],
    );
    $uriCollision = new FakeServerRequest(
        method: 'POST',
        parsedBody: [UriInterface::class => '/spoofed'],
    );

    try {
        $container->make(TypedKeyRequestEntry::class, [
            ServerRequestInterface::class => $requestCollision,
        ]);
        throw new \RuntimeException('Expected request type-key source conflict.');
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe(ServerRequestInterface::class)
            ->and($exception->source)->toBe(ServerRequestInterface::class)
            ->and($exception->parameter)->toBe('request');
    }

    try {
        $container->make(TypedKeyUriEntry::class, [
            ServerRequestInterface::class => $uriCollision,
        ]);
        throw new \RuntimeException('Expected URI type-key source conflict.');
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe(UriInterface::class)
            ->and($exception->source)->toBe(UriInterface::class)
            ->and($exception->parameter)->toBe('uri');
    }
});

it('keeps ordinary programmatic typed explicit parameters unchanged', function (): void {
    $value = new TypedKeySourceValue();
    $dto = typedKeySourceBuilder()->build()->make(TypedKeySourceDto::class, [
        TypedKeySourceContract::class => $value,
    ]);

    expect($dto->service)->toBe($value);
});

it('keeps mapped type-key conflicts identical in development and compiled production', function (): void {
    [$development, $production, $directory] = typedKeySourceContainers();
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: [TypedKeySourceContract::class => new TypedKeySourceValue()],
    );

    try {
        expect(typedKeySourceConflictSnapshot($production, $request))
            ->toBe(typedKeySourceConflictSnapshot($development, $request));
    } finally {
        cleanupTypedKeySourceDirectory($directory);
    }
});
