<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

interface MappedClassDefinitionContract {}

final readonly class MappedClassDefinitionCommand implements MappedClassDefinitionContract
{
    public function __construct(
        public string $value = 'configured-value',
        #[Header('X-Token')]
        public string $token = 'configured-token',
    ) {}
}

final readonly class MappedClassDefinitionEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public MappedClassDefinitionContract $command,
    ) {}
}

function mappedClassDefinition(): ClassDefinition
{
    return ClassDefinition::create(MappedClassDefinitionCommand::class)->constructor([
        'token' => 'configured-token',
    ]);
}

function mappedClassDefinitionBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addDefinition(MappedClassDefinitionContract::class, mappedClassDefinition());
}

test('mapped input is rejected before a runtime ClassDefinition consumes a source-bound key', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted-token')
        ->withParsedBody([
            'value' => 'payload-value',
            'token' => 'attacker-token',
        ]);

    expect(fn() => mappedClassDefinitionBuilder()->build()->make(
        MappedClassDefinitionEnvelope::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(RequestParameterSourceConflictException::class);
});

test('ordinary programmatic ClassDefinition overrides are not treated as mapped input', function (): void {
    $command = mappedClassDefinitionBuilder()->build()->make(
        MappedClassDefinitionContract::class,
        [
            'value' => 'programmatic-value',
            'token' => 'programmatic-token',
        ],
    );

    expect($command)->toBeInstanceOf(MappedClassDefinitionCommand::class)
        ->and($command->value)->toBe('programmatic-value')
        ->and($command->token)->toBe('programmatic-token');
});

test('persistent ClassDefinition cache keeps mapped request source guards in the shared runtime path', function (): void {
    $root = sys_get_temp_dir() . '/componenta-di-v5-mapped-class-definition-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                MappedClassDefinitionContract::class => mappedClassDefinition(),
            ],
        ], $cacheFile);

        $cache = require $cacheFile;
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

        expect(fn() => $container->make(MappedClassDefinitionContract::class, $context))
            ->toThrow(RequestParameterSourceConflictException::class);

        $programmatic = $container->make(MappedClassDefinitionContract::class, [
            'value' => 'programmatic-value',
            'token' => 'programmatic-token',
        ]);

        expect($programmatic)->toBeInstanceOf(MappedClassDefinitionCommand::class)
            ->and($programmatic->token)->toBe('programmatic-token');
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});
