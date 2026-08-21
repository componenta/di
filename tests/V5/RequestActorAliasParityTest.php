<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface RequestActorFixtureInterface {}

final readonly class RequestActorFixture implements RequestActorFixtureInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapActorCommandFixture extends MapRequestAttributes
{
    protected array $attributes = [RequestActorFixtureInterface::class, 'commandId'];

    protected(set) array $map = [
        RequestActorFixtureInterface::class => 'actor',
    ];
}

final readonly class ActorMappedCommandFixture
{
    public function __construct(
        public RequestActorFixture $actor,
        public string $commandId,
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

/** @return array{0:Container,1:Container,2:string} */
function actorAliasParityContainers(): array
{
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-actor-alias-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\ActorAlias' . $suffix;
    $development = (new ContainerBuilder())->build();
    $compiler = new ContainerBuilder();
    $compiled = $compiler->compileFactories(
        [ActorMappedCommandFixture::class, ActorMappedEndpointFixture::class],
        $directory,
        namespace: $namespace,
    );
    $data = $compiler->toArray();
    $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $compiled,
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

test('request attribute aliases can populate a fresh actor-aware style message in development and AOT', function (): void {
    [$development, $production, $directory] = actorAliasParityContainers();
    $actor = new RequestActorFixture();
    $request = (new ServerRequest('POST', '/commands/command-42'))
        ->withAttribute(RequestActorFixtureInterface::class, $actor)
        ->withAttribute('commandId', 'command-42');
    $provided = [ServerRequestInterface::class => $request];

    try {
        $expected = $development->call(new ActorMappedEndpointFixture(), $provided);
        $actual = $production->call(new ActorMappedEndpointFixture(), $provided);

        expect($expected->actor)->toBe($actor)
            ->and($expected->commandId)->toBe('command-42')
            ->and($actual->actor)->toBe($actor)
            ->and($actual->commandId)->toBe('command-42');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
