<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface LazyAliasMappedContract {}

#[Lazy]
class LazyAliasMappedCommand implements LazyAliasMappedContract
{
    public function __construct(
        #[Header('X-Token')]
        public string $token,
    ) {}
}

final readonly class LazyAliasMappedEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public LazyAliasMappedContract $command,
    ) {}
}

test('mapped payload identifies the conflicting header parameter behind a lazy alias', function (): void {
    $container = (new ContainerBuilder())
        ->addAlias(LazyAliasMappedContract::class, LazyAliasMappedCommand::class)
        ->build();
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted-token')
        ->withParsedBody(['token' => 'attacker-token']);

    try {
        $container->make(LazyAliasMappedEnvelope::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->dtoClass)->toBe(LazyAliasMappedCommand::class)
            ->and($exception->key)->toBe('token')
            ->and($exception->source)->toBe(Header::class)
            ->and($exception->parameter)->toBe('token');
        return;
    }

    throw new \RuntimeException('Expected mapped lazy alias source conflict.');
});
