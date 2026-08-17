<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface MappedLazyAliasCommandContract {}

#[Lazy]
class MappedLazyAliasCommand implements MappedLazyAliasCommandContract
{
    public function __construct(
        #[Header('X-Token')]
        public string $token,
    ) {}
}

final readonly class MappedLazyAliasEntry
{
    public function __construct(
        #[MapRequestPayload]
        public MappedLazyAliasCommandContract $command,
    ) {}
}

function mappedLazyAliasBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    )->addAlias(
        MappedLazyAliasCommandContract::class,
        MappedLazyAliasCommand::class,
    );
}

/** @return array{0: Container, 1: Container, 2: string} */
function mappedLazyAliasContainers(): array
{
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-mapped-lazy-alias-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\MappedLazyAlias' . $suffix;
    $development = mappedLazyAliasBuilder()->build();
    $compiler = mappedLazyAliasBuilder();
    $compiledFactories = $compiler->compileFactories(
        [MappedLazyAliasEntry::class, MappedLazyAliasCommand::class],
        $directory,
        namespace: $namespace,
    );
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

function cleanupMappedLazyAliasDirectory(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

/** @return array{class-string, string, class-string, string} */
function mappedLazyAliasConflictSnapshot(
    Container $container,
    ServerRequestInterface $request,
): array {
    try {
        $container->make(MappedLazyAliasEntry::class, [
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

    throw new RuntimeException('Expected mapped lazy alias source conflict.');
}

it('rejects mapped source conflicts before an aliased lazy object is created', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: ['token' => 'attacker-token'],
    ))->withHeader('X-Token', 'trusted-token');

    $snapshot = mappedLazyAliasConflictSnapshot(
        mappedLazyAliasBuilder()->build(),
        $request,
    );

    expect($snapshot[1])->toBe('token')
        ->and($snapshot[2])->toBe(Header::class)
        ->and($snapshot[3])->toBe('token');
});

it('keeps the lazy alias source boundary identical in development and compiled production', function (): void {
    [$development, $production, $directory] = mappedLazyAliasContainers();
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: ['token' => 'attacker-token'],
    ))->withHeader('X-Token', 'trusted-token');

    try {
        expect(mappedLazyAliasConflictSnapshot($production, $request))
            ->toBe(mappedLazyAliasConflictSnapshot($development, $request));
    } finally {
        cleanupMappedLazyAliasDirectory($directory);
    }
});
