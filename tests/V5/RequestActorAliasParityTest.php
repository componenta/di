<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Container;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface RequestActorFixtureInterface {}

final readonly class RequestActorFixture implements RequestActorFixtureInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapActorCommandFixture extends MapRequestAttributes
{
    protected array $attributes = [RequestActorFixtureInterface::class, 'commandId'];

    public protected(set) array $map = [
        RequestActorFixtureInterface::class => 'actor',
    ];
}

final readonly class ActorMappedCommandFixture
{
    public function __construct(
        public RequestActorFixture $actor,
        public string $commandId,
        public ?string $private = null,
    ) {}
}

final readonly class ActorMappedEndpointFixture
{
    public function __invoke(
        #[MapActorCommandFixture]
        ActorMappedCommandFixture $command,
    ): ActorMappedCommandFixture {
        return $command;
    }
}

/** @return array{0:Container,1:Container} */
function actorAliasParityContainers(): array
{
    return [
        (new ContainerBuilder())->build(),
        (new ContainerBuilder())->build(),
    ];
}

test('request attribute aliases populate fresh actor-aware messages for every build', function (): void {
    [$first, $second] = actorAliasParityContainers();
    $actor = new RequestActorFixture();
    $request = (new ServerRequest('POST', '/commands/command-42'))
        ->withAttribute(RequestActorFixtureInterface::class, $actor)
        ->withAttribute('commandId', 'command-42')
        ->withAttribute('private', 'must-not-be-mapped');
    $provided = [ServerRequestInterface::class => $request];

    $expected = $first->call(new ActorMappedEndpointFixture(), $provided);
    $actual = $second->call(new ActorMappedEndpointFixture(), $provided);
    if (!$expected instanceof ActorMappedCommandFixture || !$actual instanceof ActorMappedCommandFixture) {
        throw new \LogicException('Expected the endpoint to return its mapped command.');
    }

    expect($expected->actor)->toBe($actor)
        ->and($expected->commandId)->toBe('command-42')
        ->and($expected->private)->toBeNull()
        ->and($actual->actor)->toBe($actor)
        ->and($actual->commandId)->toBe('command-42')
        ->and($actual->private)->toBeNull();
});
