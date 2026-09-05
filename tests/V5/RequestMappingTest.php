<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile as NyholmUploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class HeaderCastDto
{
    public function __construct(
        #[Header('X-Count', cast: 'int')]
        public int $count,
    ) {}
}

final class MappedPayloadDto
{
    public function __construct(public string $name) {}
}

final class MappedPayloadEnvelope
{
    public function __construct(#[MapRequest] public MappedPayloadDto $dto) {}
}

final class HeaderProtectedMappedDto
{
    public function __construct(#[Header('X-Token')] public string $token) {}
}

final class HeaderProtectedEnvelope
{
    public function __construct(#[MapRequest] public HeaderProtectedMappedDto $dto) {}
}

final class MultiSourceEnvelope
{
    /** @param array<string,mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Payload, RequestDataSource::Query])]
        public array $data,
    ) {}
}

final readonly class RequestSourceAttributesTarget
{
    public function __construct(
        #[Header('X-Token')]
        public string $header,
        #[Cookie('session')]
        public string $cookie,
        #[QueryParam('page')]
        public string $query,
        #[PayloadParam('name')]
        public string $payload,
        #[RequestAttribute('actor')]
        public string $attribute,
        #[ServerParam('REMOTE_ADDR')]
        public string $server,
        #[UploadedFile('files.avatar')]
        public UploadedFileInterface $uploadedFile,
    ) {}
}

final readonly class SpecializedRequestMapsTarget
{
    /**
     * @param array<string|int,mixed> $query
     * @param array<string|int,mixed> $payload
     * @param array<string|int,mixed> $headers
     * @param array<string|int,mixed> $cookies
     * @param array<string|int,mixed> $server
     */
    public function __construct(
        #[MapQueryString]
        public array $query,
        #[MapRequestPayload]
        public array $payload,
        #[MapHeaders]
        public array $headers,
        #[MapCookies]
        public array $cookies,
        #[MapServerParams]
        public array $server,
    ) {}
}

final readonly class PayloadExtractionTarget
{
    public function __construct(
        #[PayloadParam]
        public string $inferred,
        #[PayloadParam(new ConfigPath('profile.name'))]
        public string $nested,
        #[PayloadParam(new ConfigPath('profile.missing'), default: 'fallback')]
        public string $defaulted,
    ) {}
}

final readonly class MissingNestedPayloadTarget
{
    public function __construct(
        #[PayloadParam(new ConfigPath('profile.name'))]
        public string $name,
    ) {}
}

final readonly class AllRequestSourcesTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(sources: [
            RequestDataSource::Payload,
            RequestDataSource::Query,
            RequestDataSource::Headers,
            RequestDataSource::Cookies,
            RequestDataSource::Attributes,
            RequestDataSource::Server,
            RequestDataSource::Files,
        ])]
        public array $data,
    ) {}
}

final readonly class SelectedSharedRequestSourcesTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(
            sources: [RequestDataSource::Attributes, RequestDataSource::Files],
            attributes: [MapRequest::ALL],
            files: [MapRequest::ALL],
        )]
        public array $data,
    ) {}
}

final readonly class FirstWinsRequestSourcesTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(
            sources: [RequestDataSource::Payload, RequestDataSource::Query],
            conflictPolicy: RequestDataConflictPolicy::FirstWins,
        )]
        public array $data,
    ) {}
}

final readonly class DuplicateRequestSourcesTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query, RequestDataSource::Query])]
        public array $data,
    ) {}
}

final readonly class InvalidWildcardRequestSourcesTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(
            sources: [RequestDataSource::Attributes],
            attributes: [MapRequest::ALL, 'route'],
        )]
        public array $data,
    ) {}
}

final readonly class UnknownRequestCasterTarget
{
    public function __construct(
        #[Header('X-Value', cast: 'unknown-caster')]
        public string $value,
    ) {}
}

final readonly class AmbiguousRequestDtoTarget
{
    public function __construct(
        #[MapRequest]
        public MappedPayloadDto|HeaderProtectedMappedDto $dto,
    ) {}
}

final readonly class NumericKeyRequestDtoTarget
{
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query])]
        public MappedPayloadDto $dto,
    ) {}
}

final readonly class HighPriorityTokenResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'token';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        return [$target->position, 'custom-high-priority'];
    }
}

function requestContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

test('request attributes resolve and cast through RequestResolver', function (): void {
    $request = (new ServerRequest('GET', '/'))->withHeader('X-Count', '41');
    $dto = requestContainer()->make(
        HeaderCastDto::class,
        [ServerRequestInterface::class => $request],
    );

    expect($dto->count)->toBe(41);
});

test('all single-value request sources resolve through the public container boundary', function (): void {
    $uploadedFile = new NyholmUploadedFile(
        Stream::create('avatar'),
        6,
        UPLOAD_ERR_OK,
        'avatar.txt',
        'text/plain',
    );
    $request = (new ServerRequest(
        'POST',
        '/?page=3',
        ['X-Token' => 'header-token'],
        null,
        '1.1',
        ['REMOTE_ADDR' => '127.0.0.1'],
    ))
        ->withCookieParams(['session' => 'cookie-token'])
        ->withQueryParams(['page' => '3'])
        ->withParsedBody(['name' => 'Ada'])
        ->withAttribute('actor', 'customer-42')
        ->withUploadedFiles(['files' => ['avatar' => $uploadedFile]]);

    $target = requestContainer()->make(
        RequestSourceAttributesTarget::class,
        [ServerRequestInterface::class => $request],
    );

    expect($target->header)->toBe('header-token')
        ->and($target->cookie)->toBe('cookie-token')
        ->and($target->query)->toBe('3')
        ->and($target->payload)->toBe('Ada')
        ->and($target->attribute)->toBe('customer-42')
        ->and($target->server)->toBe('127.0.0.1')
        ->and($target->uploadedFile)->toBe($uploadedFile);
});

test('specialized request maps select their documented PSR-7 source bags', function (): void {
    $request = (new ServerRequest(
        'POST',
        '/?query=value',
        ['X-Token' => 'header-value'],
        null,
        '1.1',
        ['REMOTE_ADDR' => '127.0.0.1'],
    ))
        ->withCookieParams(['session' => 'cookie-value'])
        ->withQueryParams(['query' => 'query-value'])
        ->withParsedBody(['payload' => 'payload-value']);

    $target = requestContainer()->make(
        SpecializedRequestMapsTarget::class,
        [ServerRequestInterface::class => $request],
    );

    expect($target->query)->toBe(['query' => 'query-value'])
        ->and($target->payload)->toBe(['payload' => 'payload-value'])
        ->and($target->headers)->toBe(['X-Token' => 'header-value'])
        ->and($target->cookies)->toBe(['session' => 'cookie-value'])
        ->and($target->server)->toBe(['REMOTE_ADDR' => '127.0.0.1']);
});

test('MapRequest creates nested DTOs through request-only mapped provenance', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'Ada']);
    $entry = requestContainer()->make(
        MappedPayloadEnvelope::class,
        [ServerRequestInterface::class => $request],
    );

    expect($entry->dto->name)->toBe('Ada');
});

test('nested mapped DTO input cannot shadow a declared request source', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted')
        ->withParsedBody(['token' => 'attacker']);

    expect(fn() => requestContainer()->make(
        HeaderProtectedEnvelope::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(RequestParameterSourceConflictException::class);
});

test('mapped source guard runs before a higher-priority custom resolver', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted')
        ->withParsedBody(['token' => 'attacker']);
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->addParameterResolver(new HighPriorityTokenResolver(), 5000)
        ->build();

    expect(fn() => $container->make(
        HeaderProtectedEnvelope::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(RequestParameterSourceConflictException::class);
});

test('MapRequest rejects conflicting values from multiple sources by default', function (): void {
    $request = (new ServerRequest('POST', '/?id=2'))
        ->withQueryParams(['id' => 2])
        ->withParsedBody(['id' => 1]);

    expect(fn() => requestContainer()->make(
        MultiSourceEnvelope::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(RequestDataConflictException::class);
});

test('PayloadParam infers names and traverses object payload paths with defaults', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody((object) [
        'inferred' => 'inferred-value',
        'profile' => ['name' => 'Ada'],
    ]);

    $target = requestContainer()->make(
        PayloadExtractionTarget::class,
        [ServerRequestInterface::class => $request],
    );

    expect($target->inferred)->toBe('inferred-value')
        ->and($target->nested)->toBe('Ada')
        ->and($target->defaulted)->toBe('fallback');
});

test('PayloadParam reports a required nested value hidden behind a scalar', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody([
        'profile' => 'not-a-map',
    ]);

    expect(fn() => requestContainer()->make(
        MissingNestedPayloadTarget::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(\Componenta\DI\Exception\ResolutionException::class, 'profile.name');
});

test('MapRequest exposes every declared PSR-7 source through one public mapping', function (): void {
    $uploadedFile = new NyholmUploadedFile(Stream::create('file'), 4, UPLOAD_ERR_OK);
    $request = (new ServerRequest(
        'POST',
        '/',
        ['X-Source' => 'header'],
        null,
        '1.1',
        ['server' => 'server-value'],
    ))
        ->withParsedBody(['payload' => 'payload-value'])
        ->withQueryParams(['query' => 'query-value'])
        ->withCookieParams(['cookie' => 'cookie-value'])
        ->withAttribute('attribute', 'attribute-value')
        ->withUploadedFiles(['file' => $uploadedFile]);

    $data = requestContainer()->make(
        AllRequestSourcesTarget::class,
        [ServerRequestInterface::class => $request],
    )->data;

    expect($data)->toBe([
        'payload' => 'payload-value',
        'query' => 'query-value',
        'X-Source' => 'header',
        'cookie' => 'cookie-value',
        'attribute' => 'attribute-value',
        'server' => 'server-value',
        'file' => $uploadedFile,
    ]);
});

test('MapRequest wildcard selection includes all explicit attribute and file values', function (): void {
    $uploadedFile = new NyholmUploadedFile(Stream::create('file'), 4, UPLOAD_ERR_OK);
    $request = (new ServerRequest('POST', '/'))
        ->withAttribute('route', 17)
        ->withUploadedFiles(['avatar' => $uploadedFile]);

    $data = requestContainer()->make(
        SelectedSharedRequestSourcesTarget::class,
        [ServerRequestInterface::class => $request],
    )->data;

    expect($data)->toBe(['route' => 17, 'avatar' => $uploadedFile]);
});

test('MapRequest FirstWins keeps the first provider value for duplicate source keys', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withParsedBody(['id' => 'payload'])
        ->withQueryParams(['id' => 'query']);

    $data = requestContainer()->make(
        FirstWinsRequestSourcesTarget::class,
        [ServerRequestInterface::class => $request],
    )->data;

    expect($data)->toBe(['id' => 'payload']);
});

test(
    'MapRequest rejects duplicate sources and ambiguous wildcard selectors',
    function (
        string $target,
        string $message,
    ): void {
        if ($target === '') {
            throw new \LogicException('Expected a non-empty request mapping target.');
        }

        $request = (new ServerRequest('POST', '/'))
            ->withQueryParams(['value' => 'query'])
            ->withAttribute('route', 17);

        expect(fn() => requestContainer()->make(
            $target,
            [ServerRequestInterface::class => $request],
        ))->toThrow(\Componenta\DI\Exception\ResolutionException::class, $message);
    },
)->with([
    'duplicate source' => [DuplicateRequestSourcesTarget::class, 'declared more than once'],
    'ambiguous wildcard' => [InvalidWildcardRequestSourcesTarget::class, 'wildcard must be the only selector'],
]);

test('request source casting reports an unknown caster through the parameter contract', function (): void {
    $request = (new ServerRequest('GET', '/'))->withHeader('X-Value', 'value');

    expect(fn() => requestContainer()->make(
        UnknownRequestCasterTarget::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(\Componenta\DI\Exception\ResolutionException::class, 'unknown-caster');
});

test('request DTO mapping requires one unambiguous class type', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'Ada']);

    expect(fn() => requestContainer()->make(
        AmbiguousRequestDtoTarget::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(
        \Componenta\DI\Exception\ResolutionException::class,
        'requires exactly one class type',
    );
});

test('request DTO mapping rejects positional transport keys', function (): void {
    $request = (new ServerRequest('GET', '/'))->withQueryParams([0 => 'positional']);

    expect(fn() => requestContainer()->make(
        NumericKeyRequestDtoTarget::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(
        \Componenta\DI\Exception\ResolutionException::class,
        'accepts only named string keys',
    );
});
