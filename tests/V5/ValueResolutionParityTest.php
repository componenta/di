<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\DI\Tests\Support\TestCounter;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class CastParameterParityDto
{
    public function __construct(
        #[Cast('int')]
        public int $value,
    ) {}
}

final class CastDefaultParityDto
{
    public function __construct(
        #[Cast('int', default: '7')]
        public int $value,
    ) {}
}

final class CastPropertyParityDto
{
    #[Cast('int')]
    public int $value;
}

final class InitPropertyParityDto
{
    #[Init([TestCounter::class, 'next'])]
    public int $value;
}

final class ConfigOverrideParityDto
{
    public function __construct(
        #[ConfigAttribute('value')]
        public string $value,
    ) {}
}

final class AttributedRequestTransportDto
{
    public function __construct(
        #[ConfigAttribute('request')]
        public ServerRequestInterface $request,
    ) {}
}

interface AttributedTypedOverrideA {}

interface AttributedTypedOverrideB {}

final class AttributedTypedOverrideAValue implements AttributedTypedOverrideA {}

final class AttributedTypedOverrideBValue implements AttributedTypedOverrideB {}

final class AttributedTypedOverrideDto
{
    public function __construct(
        #[ConfigAttribute('dependency')]
        public AttributedTypedOverrideA|AttributedTypedOverrideB $dependency,
    ) {}
}

final class TypedEnvironmentParityDto
{
    public function __construct(
        #[Env('PORT')]
        public int $port,
        #[Env('DEBUG')]
        public bool $debug,
    ) {}
}

final class MissingEnvironmentDefaultParityDto
{
    public function __construct(
        #[Env('PORT', default: 8080)]
        public int $port,
    ) {}
}

final readonly class EntryIdParameterParityDto
{
    public function __construct(
        #[EntryId('explicit.entry')]
        public object $entry,
    ) {}
}

final class EntryIdPropertyParityDto
{
    #[EntryId('explicit.entry')]
    public object $entry;
}

final readonly class PropertyMadeValue
{
    public function __construct(public string $value) {}
}

final class PropertyValueSourcesDto
{
    #[ConfigAttribute(new ConfigPath('application.name'))]
    public string $applicationName;

    #[ConfigAttribute(new ConfigPath('application.missing'), default: 'configured-default')]
    public string $configDefault;

    #[Env]
    public string $apiToken;

    #[Env('RATE')]
    public float $rate;

    /** @var list<string> */
    #[Env('FLAGS')]
    public array $flags;

    #[Env('RAW')]
    public mixed $raw;

    #[Make(PropertyMadeValue::class, ['value' => 'fresh-property'])]
    public PropertyMadeValue $made;
}

final class MissingPropertyEnvironmentDto
{
    #[Env('MISSING_REQUIRED_VALUE')]
    public string $required;
}

function parityValueContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

test('Cast parameter resolution stays in the parameter resolver chain', function (): void {
    $dto = parityValueContainer()->make(CastParameterParityDto::class, ['value' => '42']);

    expect($dto->value)->toBe(42);
});

test('Cast keeps its attribute-owned default contract', function (): void {
    $dto = parityValueContainer()->make(CastDefaultParityDto::class);

    expect($dto->value)->toBe(7);
});

test('Cast property reads object creation parameters rather than initialized property state', function (): void {
    $container = parityValueContainer();

    expect($container->make(CastPropertyParityDto::class, ['value' => '9'])->value)->toBe(9)
        ->and(fn() => $container->make(CastPropertyParityDto::class))
        ->toThrow(ResolutionException::class);
});

test('Init remains a property attribute handler and executes once after instantiation', function (): void {
    TestCounter::reset();

    $dto = parityValueContainer()->make(InitPropertyParityDto::class);

    expect($dto->value)->toBe(1)
        ->and(TestCounter::$value)->toBe(1);
});

test('explicit named parameters keep precedence over Config parameter resolution', function (): void {
    $container = ContainerBuilder::configure(new Config(
        ['value' => 'configured'],
        new Environment([]),
    ))->build();

    expect($container->make(ConfigOverrideParityDto::class, ['value' => 'explicit'])->value)
        ->toBe('explicit');
});

test('HTTP request transport is not a generic attributed typed override', function (): void {
    $configured = new ServerRequest('GET', '/configured');
    $transport = new ServerRequest('GET', '/transport');
    $explicit = new ServerRequest('GET', '/explicit');
    $container = ContainerBuilder::configure(new Config(
        ['request' => $configured],
        new Environment([]),
    ))->build();

    expect($container->make(AttributedRequestTransportDto::class, [
        ServerRequestInterface::class => $transport,
    ])->request)->toBe($configured)
        ->and($container->make(AttributedRequestTransportDto::class, [
            ServerRequestInterface::class => $transport,
            'request' => $explicit,
        ])->request)->toBe($explicit);
});

test('attributed typed overrides must satisfy the type named by their key', function (): void {
    $configured = new AttributedTypedOverrideAValue();
    $validOverride = new AttributedTypedOverrideAValue();
    $wrongKeyValue = new AttributedTypedOverrideBValue();
    $container = ContainerBuilder::configure(new Config(
        ['dependency' => $configured],
        new Environment([]),
    ))->build();

    expect($container->make(AttributedTypedOverrideDto::class, [
        AttributedTypedOverrideA::class => $validOverride,
    ])->dependency)->toBe($validOverride)
        ->and($container->make(AttributedTypedOverrideDto::class, [
            AttributedTypedOverrideA::class => $wrongKeyValue,
        ])->dependency)->toBe($configured);
});

test('Env converts values for scalar target types', function (): void {
    $container = ContainerBuilder::configure(new Config(
        [],
        new Environment(['PORT' => '3306', 'DEBUG' => 'true']),
    ))->build();

    $dto = $container->make(TypedEnvironmentParityDto::class);

    expect($dto->port)->toBe(3306)
        ->and($dto->debug)->toBeTrue();
});

test('Env uses its attribute default when the runtime environment key is missing', function (): void {
    $container = ContainerBuilder::configure(new Config([], new Environment([])))->build();

    expect($container->make(MissingEnvironmentDefaultParityDto::class)->port)->toBe(8080);
});

test('EntryId resolves the exact configured entry for constructor parameters and properties', function (): void {
    $entry = new \stdClass();
    $container = (new ContainerBuilder())
        ->addService('explicit.entry', $entry)
        ->build();

    expect($container->make(EntryIdParameterParityDto::class)->entry)->toBe($entry)
        ->and($container->make(EntryIdPropertyParityDto::class)->entry)->toBe($entry);
});

test('EntryId preserves the container missing-entry failure contract', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(EntryIdParameterParityDto::class))
        ->toThrow(\Componenta\DI\Exception\NotFoundException::class, 'explicit.entry')
        ->and(fn() => $container->make(EntryIdPropertyParityDto::class))
        ->toThrow(\Componenta\DI\Exception\NotFoundException::class, 'explicit.entry');
});

test('Config Env and Make resolve properties through the same public object pipeline', function (): void {
    $config = new Config(
        ['application' => ['name' => 'Componenta']],
        new Environment([
            'API_TOKEN' => 'secret',
            'RATE' => '1.25',
            'FLAGS' => ['first', 'second'],
            'RAW' => new \stdClass(),
        ]),
    );
    $container = ContainerBuilder::configure($config)->build();

    $dto = $container->make(PropertyValueSourcesDto::class);

    expect($dto->applicationName)->toBe('Componenta')
        ->and($dto->configDefault)->toBe('configured-default')
        ->and($dto->apiToken)->toBe('secret')
        ->and($dto->rate)->toBe(1.25)
        ->and($dto->flags)->toBe(['first', 'second'])
        ->and($dto->raw)->toBeInstanceOf(\stdClass::class)
        ->and($dto->made->value)->toBe('fresh-property');
});

test('missing required Env property reports the public resolution context', function (): void {
    $container = ContainerBuilder::configure(new Config([], new Environment([])))->build();

    expect(fn() => $container->make(MissingPropertyEnvironmentDto::class))
        ->toThrow(
            ResolutionException::class,
            'Environment variable "MISSING_REQUIRED_VALUE" is not defined',
        );
});
