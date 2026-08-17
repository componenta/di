<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface MappedAliasCommandContract {}

final readonly class MappedAliasCommand implements MappedAliasCommandContract
{
    public function __construct(
        public string $value,
        #[Header('X-Token')]
        public string $token,
    ) {}
}

final readonly class MappedAliasEntry
{
    public function __construct(
        #[MapRequestPayload]
        public MappedAliasCommandContract $command,
    ) {}
}

function mappedAliasBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    )->addAlias(
        MappedAliasCommandContract::class,
        MappedAliasCommand::class,
    );
}

/** @return array{0: Container, 1: Container, 2: string} */
function mappedAliasContainers(): array
{
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-mapped-alias-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\MappedAlias' . $suffix;
    $development = mappedAliasBuilder()->build();
    $compiler = mappedAliasBuilder();
    $compiledFactories = $compiler->compileFactories(
        [MappedAliasEntry::class, MappedAliasCommand::class],
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

function cleanupMappedAliasDirectory(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

/** @return array{class-string, string, class-string, string} */
function mappedAliasConflictSnapshot(
    Container $container,
    ServerRequestInterface $request,
): array {
    try {
        $container->make(MappedAliasEntry::class, [
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

    throw new RuntimeException('Expected mapped alias source conflict.');
}

it('rejects mapped input that reaches a source-bound constructor through an alias', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: [
            'value' => 'payload-value',
            'token' => 'attacker-token',
        ],
    ))->withHeader('X-Token', 'trusted-token');

    $snapshot = mappedAliasConflictSnapshot(mappedAliasBuilder()->build(), $request);

    expect($snapshot[1])->toBe('token')
        ->and($snapshot[2])->toBe(Header::class)
        ->and($snapshot[3])->toBe('token');
});

it('resolves an aliased mapped DTO from its declared source when no mapped collision exists', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: ['value' => 'payload-value'],
    ))->withHeader('X-Token', 'trusted-token');
    $entry = mappedAliasBuilder()->build()->make(MappedAliasEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($entry->command)->toBeInstanceOf(MappedAliasCommand::class)
        ->and($entry->command->value)->toBe('payload-value')
        ->and($entry->command->token)->toBe('trusted-token');
});

it('keeps aliased mapped source conflicts identical in development and compiled production', function (): void {
    [$development, $production, $directory] = mappedAliasContainers();
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: [
            'value' => 'payload-value',
            'token' => 'attacker-token',
        ],
    ))->withHeader('X-Token', 'trusted-token');

    try {
        expect(mappedAliasConflictSnapshot($production, $request))
            ->toBe(mappedAliasConflictSnapshot($development, $request));
    } finally {
        cleanupMappedAliasDirectory($directory);
    }
});
