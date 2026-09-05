<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Tests\Support\ContainerBuilder;
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
    if (!$command instanceof MappedClassDefinitionCommand) {
        throw new \LogicException('The class definition contract resolved to an unexpected type.');
    }

    expect($command)->toBeInstanceOf(MappedClassDefinitionCommand::class)
        ->and($command->value)->toBe('programmatic-value')
        ->and($command->token)->toBe('programmatic-token');
});
