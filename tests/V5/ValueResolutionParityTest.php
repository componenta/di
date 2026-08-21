<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Init;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\DI\Tests\Support\TestCounter;

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
    $container = ContainerBuilder::configure(new Config(['value' => 'configured']))->build();

    expect($container->make(ConfigOverrideParityDto::class, ['value' => 'explicit'])->value)
        ->toBe('explicit');
});

test('attributed typed overrides must satisfy the type named by their key', function (): void {
    $configured = new AttributedTypedOverrideAValue();
    $validOverride = new AttributedTypedOverrideAValue();
    $wrongKeyValue = new AttributedTypedOverrideBValue();
    $container = ContainerBuilder::configure(new Config([
        'dependency' => $configured,
    ]))->build();

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
    $container = ContainerBuilder::configure(new Config([]))->build();

    expect($container->make(MissingEnvironmentDefaultParityDto::class)->port)->toBe(8080);
});
