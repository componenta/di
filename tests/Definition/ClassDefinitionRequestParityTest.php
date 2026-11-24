<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Definition\ClassDefinitionRequestParity;

use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
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

test('ClassDefinition bound to an interface consumes mapped fields without activating constructor attributes', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted-token')
        ->withParsedBody([
            'value' => 'payload-value',
            'token' => 'payload-token',
        ]);

    $envelope = mappedClassDefinitionBuilder()->build()->make(
        MappedClassDefinitionEnvelope::class,
        [ServerRequestInterface::class => $request],
    );
    Assert::assertInstanceOf(MappedClassDefinitionEnvelope::class, $envelope);
    if (!$envelope->command instanceof MappedClassDefinitionCommand) {
        throw new \LogicException('Expected the explicitly configured command.');
    }
    expect($envelope->command->value)->toBe('payload-value')
        ->and($envelope->command->token)->toBe('payload-token');
});

test('ordinary programmatic ClassDefinition overrides are not treated as mapped input', function (): void {
    $container = mappedClassDefinitionBuilder()->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'mapped', 'token' => 'mapped-token']);
    $mapped = $container->make(MappedClassDefinitionEnvelope::class, [ServerRequestInterface::class => $request]);
    Assert::assertInstanceOf(MappedClassDefinitionEnvelope::class, $mapped);
    Assert::assertInstanceOf(MappedClassDefinitionCommand::class, $mapped->command);
    expect($mapped->command->value)->toBe('mapped')
        ->and($mapped->command->token)->toBe('mapped-token');

    $command = $container->make(
        MappedClassDefinitionContract::class,
        [
            'value' => 'programmatic-value',
            'token' => 'programmatic-token',
        ],
    );
    if (!$command instanceof MappedClassDefinitionCommand) {
        throw new \LogicException('The class definition contract resolved to an unexpected type.');
    }

    expect($command)->not->toBe($mapped->command)
        ->and($command->value)->toBe('programmatic-value')
        ->and($command->token)->toBe('programmatic-token');
});
