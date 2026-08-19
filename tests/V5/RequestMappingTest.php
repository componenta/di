<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

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

final readonly class HighPriorityTokenResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'token';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
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
