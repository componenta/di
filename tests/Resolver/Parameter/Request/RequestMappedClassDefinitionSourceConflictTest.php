<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface MappedDefinitionCommandContract {}

final readonly class MappedDefinitionCommand implements MappedDefinitionCommandContract
{
    public function __construct(
        public string $value = 'configured-value',
        #[Header('X-Token')]
        public string $token = 'configured-token',
    ) {}
}

final readonly class MappedDefinitionEntry
{
    public function __construct(
        #[MapRequestPayload]
        public MappedDefinitionCommandContract $command,
    ) {}
}

function mappedDefinitionClassDefinition(): ClassDefinition
{
    return ClassDefinition::create(MappedDefinitionCommand::class)->constructor([
        'token' => 'configured-token',
    ]);
}

/** @return array<string, mixed> */
function mappedDefinitionConfigData(): array
{
    $data = (new ConfigProvider())();
    $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
    $factories = $dependencies[ConfigKey::FACTORIES] ?? [];
    $factories[MappedDefinitionCommandContract::class] = mappedDefinitionClassDefinition();
    $dependencies[ConfigKey::FACTORIES] = $factories;
    $data[ConfigKey::DEPENDENCIES] = $dependencies;

    return $data;
}

function mappedDefinitionBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config(mappedDefinitionConfigData()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    );
}

it('rejects mapped input before a runtime ClassDefinition can consume it as an override', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: [
            'value' => 'payload-value',
            'token' => 'attacker-token',
        ],
    ))->withHeader('X-Token', 'trusted-token');

    expect(fn() => mappedDefinitionBuilder()->build()->make(
        MappedDefinitionEntry::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(RequestParameterSourceConflictException::class);
});

it('keeps ordinary programmatic ClassDefinition overrides unchanged', function (): void {
    $container = ContainerBuilder::configure(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                MappedDefinitionCommandContract::class => mappedDefinitionClassDefinition(),
            ],
        ],
    ]))->build();

    $command = $container->make(MappedDefinitionCommandContract::class, [
        'value' => 'programmatic-value',
        'token' => 'programmatic-token',
    ]);

    expect($command)->toBeInstanceOf(MappedDefinitionCommand::class)
        ->and($command->value)->toBe('programmatic-value')
        ->and($command->token)->toBe('programmatic-token');
});

it('embeds the mapped source guard in persistent ClassDefinition cache code', function (): void {
    $root = sys_get_temp_dir() . '/componenta-mapped-definition-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                MappedDefinitionCommandContract::class => mappedDefinitionClassDefinition(),
            ],
        ], $cacheFile);

        $cache = require $cacheFile;
        expect($cache[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][MappedDefinitionCommandContract::class])
            ->toBeInstanceOf(Closure::class);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            $root,
        )->build();
        $mapped = [
            'value' => 'payload-value',
            'token' => 'attacker-token',
        ];
        $context = MappedRequestContext::with($mapped, $mapped);

        try {
            $container->make(MappedDefinitionCommandContract::class, $context);
            throw new RuntimeException('Expected cached ClassDefinition source conflict.');
        } catch (RequestParameterSourceConflictException $exception) {
            expect($exception->key)->toBe('token')
                ->and($exception->source)->toBe(Header::class)
                ->and($exception->parameter)->toBe('token');
        }

        $programmatic = $container->make(MappedDefinitionCommandContract::class, [
            'value' => 'programmatic-value',
            'token' => 'programmatic-token',
        ]);

        expect($programmatic)->toBeInstanceOf(MappedDefinitionCommand::class)
            ->and($programmatic->token)->toBe('programmatic-token');
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});
