<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
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

function lazyAliasMappedBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())->addAlias(
        LazyAliasMappedContract::class,
        LazyAliasMappedCommand::class,
    );
}

/** @return array{0:Container,1:Container,2:string} */
function lazyAliasMappedContainers(): array
{
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-lazy-alias-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\LazyAlias' . $suffix;
    $development = lazyAliasMappedBuilder()->build();
    $compiler = lazyAliasMappedBuilder();
    $compiled = $compiler->compileFactories(
        [LazyAliasMappedEnvelope::class, LazyAliasMappedCommand::class],
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

/** @return array{class-string,string,class-string,string} */
function lazyAliasConflictSnapshot(Container $container, ServerRequestInterface $request): array
{
    try {
        $container->make(LazyAliasMappedEnvelope::class, [
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

    throw new \RuntimeException('Expected mapped lazy alias source conflict.');
}

test('mapped source conflict is rejected before an aliased lazy object in development and AOT', function (): void {
    [$development, $production, $directory] = lazyAliasMappedContainers();
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted-token')
        ->withParsedBody(['token' => 'attacker-token']);

    try {
        expect(lazyAliasConflictSnapshot($production, $request))
            ->toBe(lazyAliasConflictSnapshot($development, $request));
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
