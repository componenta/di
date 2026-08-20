<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

interface TypedMappedSourceContract {}

final readonly class TypedMappedSourceValue implements TypedMappedSourceContract {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TypedMappedParameterSource implements ParameterSourceAttributeInterface {}

final readonly class TypedMappedSourceDto
{
    public function __construct(
        #[TypedMappedParameterSource]
        public TypedMappedSourceContract $service,
    ) {}
}

final readonly class TypedMappedSourceEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public TypedMappedSourceDto $dto,
    ) {}
}

final readonly class TypedMappedRequestDto
{
    public function __construct(
        #[CurrentRequest]
        public ServerRequestInterface $request,
    ) {}
}

final readonly class TypedMappedRequestEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public TypedMappedRequestDto $dto,
    ) {}
}

final readonly class TypedMappedUriDto
{
    public function __construct(
        #[CurrentUri]
        public UriInterface $uri,
    ) {}
}

final readonly class TypedMappedUriEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public TypedMappedUriDto $dto,
    ) {}
}

interface TypedUnionLeft {}
interface TypedUnionRight {}

final readonly class TypedUnionLeftValue implements TypedUnionLeft {}
final readonly class TypedUnionRightValue implements TypedUnionRight {}

final readonly class TypedUnionTarget
{
    public function __construct(public TypedUnionLeft|TypedUnionRight $service) {}
}

test('mapped type keys cannot shadow source-bound object parameters', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody([
        TypedMappedSourceContract::class => new TypedMappedSourceValue(),
    ]);

    try {
        (new ContainerBuilder())->build()->make(TypedMappedSourceEnvelope::class, [
            ServerRequestInterface::class => $request,
        ]);
        throw new \RuntimeException('Expected mapped type-key source conflict.');
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->key)->toBe(TypedMappedSourceContract::class)
            ->and($exception->source)->toBe(TypedMappedParameterSource::class)
            ->and($exception->parameter)->toBe('service');
    }
});

test('mapped type keys cannot spoof explicit current request sources', function (): void {
    $container = (new ContainerBuilder())->build();
    $spoofedRequest = new ServerRequest('GET', '/spoofed');

    $requestCollision = (new ServerRequest('POST', '/'))->withParsedBody([
        ServerRequestInterface::class => $spoofedRequest,
    ]);
    expect(fn() => $container->make(TypedMappedRequestEnvelope::class, [
        ServerRequestInterface::class => $requestCollision,
    ]))->toThrow(RequestParameterSourceConflictException::class);

    $uriCollision = (new ServerRequest('POST', '/'))->withParsedBody([
        UriInterface::class => '/spoofed',
    ]);
    expect(fn() => $container->make(TypedMappedUriEnvelope::class, [
        ServerRequestInterface::class => $uriCollision,
    ]))->toThrow(RequestParameterSourceConflictException::class);
});

test('ordinary programmatic typed explicit parameters remain valid', function (): void {
    $value = new TypedMappedSourceValue();
    $dto = (new ContainerBuilder())->build()->make(TypedMappedSourceDto::class, [
        TypedMappedSourceContract::class => $value,
    ]);

    expect($dto->service)->toBe($value);
});

test('typed explicit keys in unions accept only values implementing that exact key', function (): void {
    $container = (new ContainerBuilder())->build();
    $right = new TypedUnionRightValue();

    expect($container->make(TypedUnionTarget::class, [
        TypedUnionRight::class => $right,
    ])->service)->toBe($right)
        ->and(fn() => $container->make(TypedUnionTarget::class, [
            TypedUnionLeft::class => $right,
        ]))->toThrow(ResolutionException::class);
});
