<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

interface NonImplicitServerRequestSubtype extends ServerRequestInterface {}

interface NonImplicitUriSubtype extends UriInterface {}

final readonly class NonImplicitPsrSubtypeDto
{
    public function __construct(
        public NonImplicitServerRequestSubtype $request,
        public NonImplicitUriSubtype $uri,
    ) {}
}

it('does not reserve PSR request and URI subtypes that runtime resolution does not source implicitly', function (): void {
    MappedRequestParameterSourceGuard::assertNoConflicts(
        NonImplicitPsrSubtypeDto::class,
        [
            'request' => 'mapped-request',
            'uri' => 'mapped-uri',
            NonImplicitServerRequestSubtype::class => 'mapped-request-by-type',
            NonImplicitUriSubtype::class => 'mapped-uri-by-type',
        ],
    );

    expect(true)->toBeTrue();
});
