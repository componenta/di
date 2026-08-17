<?php

declare(strict_types=1);

use Attribute;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config as ApplicationConfig;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final readonly class SourceBoundHeaderDto
{
    public function __construct(
        public string $value,
        #[Header('X-Token')]
        public string $token,
    ) {}
}

final readonly class SourceBoundHeaderEntry
{
    public function __construct(
        #[MapRequestPayload(map: ['raw' => 'token'])]
        public SourceBoundHeaderDto $dto,
    ) {}
}

final readonly class SourceBoundConfigDto
{
    public function __construct(
        #[Config('security.realm')]
        public string $realm,
    ) {}
}

final readonly class SourceBoundConfigEntry
{
    public function __construct(
        #[MapRequestPayload]
        public SourceBoundConfigDto $dto,
    ) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ExternalParameterSource implements ParameterSourceAttributeInterface {}

final readonly class ExternalSourceDto
{
    public function __construct(
        #[ExternalParameterSource]
        public string $external,
    ) {}
}

final readonly class ExternalSourceEntry
{
    public function __construct(
        #[MapRequestPayload]
        public ExternalSourceDto $dto,
    ) {}
}

final readonly class RequestContextNameDto
{
    public function __construct(public ServerRequestInterface $request) {}
}

final readonly class RequestContextNameEntry
{
    public function __construct(
        #[MapRequestPayload]
        public RequestContextNameDto $dto,
    ) {}
}

final readonly class UriContextNameDto
{
    public function __construct(public UriInterface $uri) {}
}

final readonly class UriContextNameEntry
{
    public function __construct(
        #[MapRequestPayload]
        public UriContextNameDto $dto,
    ) {}
}

final readonly class NestedMapperSourceDto
{
    public function __construct(
        #[MapQueryString]
        public SourceBoundConfigDto $query,
    ) {}
}

final readonly class NestedMapperSourceEntry
{
    public function __construct(
        #[MapRequestPayload]
        public NestedMapperSourceDto $dto,
    ) {}
}

function requestParameterSourceConflictBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new ApplicationConfig((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    );
}

/** @return array{0: Container, 1: Container, 2: string} */
function requestParameterSourceConflictContainers(): array
{
    $directory = sys_get_temp_dir()
        . '/componenta-request-parameter-source-conflict-'
        . bin2hex(random_bytes(5));
    $development = requestParameterSourceConflictBuilder()->build();
    $compiler = requestParameterSourceConflictBuilder();
    $compiledFactories = $compiler->compileFactories([
        SourceBoundHeaderEntry::class,
        SourceBoundHeaderDto::class,
    ], $directory);
    $configData = $compiler->toArray();
    $dependencies = $configData[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $compiledFactories,
    );

    $production = ContainerBuilder::configureFromCache(
        new ApplicationConfig([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
        $directory,
    )->build();

    return [$development, $production, $directory];
}

function cleanupRequestParameterSourceConflictDirectory(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

/**
 * @return array{class-string, class-string, string, class-string, string}
 */
function requestParameterSourceConflictSnapshot(
    Container $container,
    ServerRequestInterface $request,
): array {
    try {
        $container->make(SourceBoundHeaderEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        return [
            $exception::class,
            $exception->dtoClass,
            $exception->key,
            $exception->source,
            $exception->getMessage(),
        ];
    }

    throw new RuntimeException('Expected request parameter source conflict.');
}

it('marks built-in parameter sources while leaving Cast as a transformer', function (): void {
    $sourceAttributes = [
        Config::class,
        Env::class,
        EntryId::class,
        Make::class,
        CurrentUser::class,
        Header::class,
        Cookie::class,
        QueryParam::class,
        PayloadParam::class,
        RequestAttribute::class,
        ServerParam::class,
        UploadedFile::class,
        MapRequestPayload::class,
        MapQueryString::class,
        MapHeaders::class,
        MapCookies::class,
        MapRequestAttributes::class,
        MapServerParams::class,
        MapUploadedFiles::class,
    ];

    foreach ($sourceAttributes as $sourceAttribute) {
        expect(is_a($sourceAttribute, ParameterSourceAttributeInterface::class, true))->toBeTrue();
    }

    expect(is_a(Cast::class, ParameterSourceAttributeInterface::class, true))->toBeFalse();
});

it('rejects transformed mapped data that targets a request source-bound parameter', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: [
            'value' => 'payload-value',
            'raw' => 'attacker-token',
        ],
    ))->withHeader('X-Token', 'trusted-token');
    $container = requestParameterSourceConflictBuilder()->build();

    try {
        $container->make(SourceBoundHeaderEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->dtoClass)->toBe(SourceBoundHeaderDto::class)
            ->and($exception->key)->toBe('token')
            ->and($exception->source)->toBe(Header::class);

        return;
    }

    throw new RuntimeException('Expected request parameter source conflict.');
});

it('rejects mapped data that targets a non-request DI source', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['realm' => 'attacker-realm'],
    );
    $container = requestParameterSourceConflictBuilder()->build();

    try {
        $container->make(SourceBoundConfigEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->dtoClass)->toBe(SourceBoundConfigDto::class)
            ->and($exception->key)->toBe('realm')
            ->and($exception->source)->toBe(Config::class);

        return;
    }

    throw new RuntimeException('Expected request parameter source conflict.');
});

it('honours source markers declared by integration packages', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['external' => 'attacker-value'],
    );
    $container = requestParameterSourceConflictBuilder()->build();

    try {
        $container->make(ExternalSourceEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->dtoClass)->toBe(ExternalSourceDto::class)
            ->and($exception->key)->toBe('external')
            ->and($exception->source)->toBe(ExternalParameterSource::class);

        return;
    }

    throw new RuntimeException('Expected request parameter source conflict.');
});

it('reserves the ServerRequestInterface constructor parameter name during mapping', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['request' => 'attacker-value'],
    );
    $container = requestParameterSourceConflictBuilder()->build();

    try {
        $container->make(RequestContextNameEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe('request')
            ->and($exception->source)->toBe(ServerRequestInterface::class);

        return;
    }

    throw new RuntimeException('Expected request parameter source conflict.');
});

it('reserves the UriInterface constructor parameter name during mapping', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['uri' => 'attacker-value'],
    );
    $container = requestParameterSourceConflictBuilder()->build();

    try {
        $container->make(UriContextNameEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe('uri')
            ->and($exception->source)->toBe(UriInterface::class);

        return;
    }

    throw new RuntimeException('Expected request parameter source conflict.');
});

it('rejects mapped data that targets a nested mapper source', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['query' => 'attacker-value'],
    );
    $container = requestParameterSourceConflictBuilder()->build();

    try {
        $container->make(NestedMapperSourceEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe('query')
            ->and($exception->source)->toBe(MapQueryString::class);

        return;
    }

    throw new RuntimeException('Expected request parameter source conflict.');
});

it('does not change ordinary programmatic make explicit overrides', function (): void {
    $dto = requestParameterSourceConflictBuilder()->build()->make(
        SourceBoundHeaderDto::class,
        [
            'value' => 'programmatic-value',
            'token' => 'explicit-token',
        ],
    );

    expect($dto->value)->toBe('programmatic-value')
        ->and($dto->token)->toBe('explicit-token');
});

it('keeps mapped parameter source conflicts identical in development and compiled production', function (): void {
    [$development, $production, $directory] = requestParameterSourceConflictContainers();
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: [
            'value' => 'payload-value',
            'raw' => 'attacker-token',
        ],
    ))->withHeader('X-Token', 'trusted-token');

    try {
        expect(requestParameterSourceConflictSnapshot($production, $request))
            ->toBe(requestParameterSourceConflictSnapshot($development, $request));
    } finally {
        cleanupRequestParameterSourceConflictDirectory($directory);
    }
});
